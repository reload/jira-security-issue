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
        $this->issueType = \getenv('JIRA_ISSUETYPE') ?: 'Bug';

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
            'project key' => 'JIRA_PROJECT',
            'Jira host' => 'JIRA_HOST',
            'Jira user' => 'JIRA_USER',
            'Jira token' => 'JIRA_TOKEN',
        ];

        foreach ($envVars as $desc => $name) {
            if (!\getenv($name)) {
                throw new RuntimeException(\sprintf('No %s supplied, please set %s environment variable', $desc, $name));
            }
        }

        if (!$this->title) {
            throw new RuntimeException('No title supplied');
        }

        if (!$this->body) {
            throw new RuntimeException('No body supplied');
        }
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

        $followers = [];
        $watchers = \getenv('JIRA_WATCHERS');

        if ($watchers) {
            $watchers = \explode(',', $watchers);

            foreach ($watchers as $watcher) {
                $accountId = $this->userNameByEmail($watcher);

                if (!$accountId) {
                    continue;
                }

                $this->issueService->addWatcher($ret->key, $accountId);
                $followers[] = $accountId;
            }

        }

        $comment = $followers ?
            $this->createComment(sprintf(self::WATCHERS_TEXT, $this->formatFollowers($followers))) :
            $this->createComment(self::NO_WATCHERS_TEXT);

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

    public function formatFollowers(array $followers): string
    {
        $followers = array_map(function ($follower) {
            return '[~' . $follower. ']';
        }, $followers);

        $last = array_pop($followers);

        return $followers ? implode(', ', $followers) . ' and ' . $last : $last;
    }
}
