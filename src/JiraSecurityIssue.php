<?php

declare(strict_types=1);

namespace Reload;

use JiraRestApi\Configuration\ArrayConfiguration;
use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\Issue\Visibility;
use JiraRestApi\JiraException;
use JiraRestApi\User\UserService;
use RuntimeException;
use Throwable;

class JiraSecurityIssue
{
    public const WATCHERS_TEXT = "This issue is being followed by %s";
    public const NO_WATCHERS_TEXT = "No watchers on this issue, remember to notify relevant people.";
    public const NOT_FOUND_WATCHERS_TEXT = "Could not find user for %s, please check the users listed in JIRA_WATCHERS.";

    /**
     * Service used for interacting with Jira issues.
     *
     * @var \JiraRestApi\Issue\IssueService
     */
    protected $issueService;

    /**
     * Service used for interaction with Jira users.
     *
     * @var \JiraRestApi\User\UserService
     */
    protected $userService;

    /**
     * Jira project to create issue in.
     *
     * @var string
     */
    protected $project;

    /**
     * Type of issue to create.
     *
     * @var string
     */
    protected $issueType = 'Bug';

    /**
     * Watchers for the issue.
     *
     * @var array<string>
     */
    protected $watchers = [];

    /**
     * Issue title.
     *
     * @var string
     */
    protected $title;

    /**
     * Issue body text.
     *
     * @var string
     */
    protected $body;

    /**
     * Labels used for finding existing issue.
     *
     * @var array<string>
     */
    protected $keyLabels = [];

    public function __construct()
    {
        $this->project = \getenv('JIRA_PROJECT') ?: '';
        $this->issueType = \getenv('JIRA_ISSUE_TYPE') ?: 'Bug';

        $watchers = \getenv('JIRA_WATCHERS');

        if ($watchers) {
            $this->watchers = \explode(',', $watchers);
        }

        $conf = [
            'jiraHost' => \getenv('JIRA_HOST'),
            'jiraUser' => \getenv('JIRA_USER'),
            'jiraPassword' => \getenv('JIRA_TOKEN'),
        ];

        $this->issueService = new IssueService(new ArrayConfiguration($conf));
        $this->userService = new UserService(new ArrayConfiguration($conf));
    }

    /**
     * Set issue service, primarily for testing.
     */
    public function setIssueService(IssueService $issueService): JiraSecurityIssue
    {
        $this->issueService = $issueService;

        return $this;
    }

    /**
     * Set user service, primarily for testing.
     */
    public function setUserService(UserService $userService): JiraSecurityIssue
    {
        $this->userService = $userService;

        return $this;
    }

    public function validate(): void
    {
        $envVars = [
            'Jira host' => 'JIRA_HOST',
            'Jira user' => 'JIRA_USER',
            'Jira token' => 'JIRA_TOKEN',
        ];

        foreach ($envVars as $desc => $name) {
            if (!\getenv($name)) {
                throw new RuntimeException(\sprintf('No %s supplied, please set %s environment variable', $desc, $name));
            }
        }

        if (!$this->project && !\getenv('JIRA_PROJECT')) {
            throw new RuntimeException('No project key supplied, please set JIRA_PROJECT environment variable');
        }

        if (!$this->title) {
            throw new RuntimeException('No title supplied');
        }

        if (!$this->body) {
            throw new RuntimeException('No body supplied');
        }
    }

    public function setProject(string $project): JiraSecurityIssue
    {
        $this->project = $project;

        return $this;
    }

    public function setTitle(string $title): JiraSecurityIssue
    {
        $this->title = $title;

        return $this;
    }

    public function setKeyLabel(string $string): JiraSecurityIssue
    {
        $this->keyLabels[] = $string;

        return $this;
    }

    public function setWatcher(string $watcher): JiraSecurityIssue
    {
        $this->watchers[] = $watcher;

        return $this;
    }

    public function setBody(string $body): JiraSecurityIssue
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Ensure that the issue exists.
     *
     * @return string the issue id.
     */
    public function ensure(): string
    {
        $existing = $this->exists();

        if ($existing) {
            return $existing;
        }

        $issueField = new IssueField();
        $issueField->setProjectKey($this->project)
            ->setSummary($this->title)
            ->setIssueType($this->issueType)
            ->setDescription($this->body);

        foreach ($this->keyLabels as $label) {
            $issueField->addLabel($label);
        }

        try {
            $ret = $this->issueService->create($issueField);
        } catch (Throwable $t) {
            throw new RuntimeException("Could not create issue: {$t->getMessage()}");
        }

        $addedWatchers = [];
        $notFoundWatchers = [];

        foreach ($this->watchers as $watcher) {
            $accountId = $this->userNameByEmail($watcher);

            if (!$accountId) {
                $notFoundWatchers[] = $watcher;

                continue;
            }

            $this->issueService->addWatcher($ret->key, $accountId);
            $addedWatchers[] = $accountId;
        }

        $commentText = $addedWatchers ?
            sprintf(self::WATCHERS_TEXT, $this->formatUsers($addedWatchers)) :
            self::NO_WATCHERS_TEXT;

        if ($notFoundWatchers) {
            $commentText .= "\n\n" . sprintf(self::NOT_FOUND_WATCHERS_TEXT, $this->formatQuoted($notFoundWatchers));
        }

        $comment = $this->createComment($commentText);

        $this->issueService->addComment($ret->key, $comment);

        return $ret->key;
    }

    /**
     * Check if the issue is already created.
     *
     * @return ?string ID of existing issue, or null if not found.
     */
    public function exists(): ?string
    {
        $this->validate();

        if (!$this->keyLabels) {
            return null;
        }

        $jql = "PROJECT = '{$this->project}' ";

        foreach ($this->keyLabels as $label) {
            $jql .= "AND labels IN ('{$label}') ";
        }

        $jql .= "ORDER BY created DESC";

        $result = $this->issueService->search($jql);

        if ($result->total > 0) {
            return \reset($result->issues)->key;
        }

        return null;
    }

    public function userNameByEmail(string $email): ?string
    {
        $users = $this->userDataByEmail($email);

        if (!$users) {
            return null;
        }

        $user = \array_pop($users);

        return $user->name;
    }

    /**
     * @return array<\JiraRestApi\User\User>
     */
    public function userDataByEmail(string $email): array
    {
        try {
            $paramArray = [
                'query' => $email,
                'project' => $this->project,
                'maxResults' => 1,
            ];

            $users = $this->userService->findAssignableUsers($paramArray);

            if ($users) {
                return $users;
            }
        } catch (JiraException $e) {
            // Fall through to returning empty array.
        }

        return [];
    }

    public function createComment(string $text): Comment
    {
        $comment = new Comment();
        $comment->setBody($text);

        $visibility = new Visibility();
        $visibility->setType('role');
        $visibility->setValue('Developers');

        $comment->visibility = $visibility;

        return $comment;
    }

    public function formatUsers(array $users): string
    {
        $users = array_map(function ($user) {
            return '[~' . $user. ']';
        }, $users);

        return $this->formatMultiple($users);
    }

    public function formatQuoted(array $wathers): string
    {
        $wathers = array_map(function ($wather) {
            return '"' . $wather. '"';
        }, $wathers);

        return $this->formatMultiple($wathers);
    }

    public function formatMultiple(array $strings): string
    {
        $last = array_pop($strings);

        return $strings ? implode(', ', $strings) . ' and ' . $last : $last;
    }
}
