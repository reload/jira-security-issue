<?php

declare(strict_types=1);

namespace Reload;

use JiraRestApi\Issue\IssueService;
use JiraRestApi\User\UserService;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;

class JiraSecurityIssueTest extends TestCase
{
    public function setUp(): void
    {
        \putenv('JIRA_HOST=https://localhost');
        \putenv('JIRA_USER=user');
        \putenv('JIRA_TOKEN=pass');
        \putenv('JIRA_PROJECT=ABC');
        \putenv('JIRA_ISSUETYPE=Mytype');

        $this->issueService = $this->prophesize(IssueService::class);
        $this->userService = $this->prophesize(UserService::class);
    }

    /**
     * Create a new, properly mocked, JiraSecurityIssue.
     */
    protected function newIssue(): JiraSecurityIssue
    {
        $issue = new JiraSecurityIssue();
        $issue->setIssueService($this->issueService->reveal());
        $issue->setUserService($this->userService->reveal());

        return $issue;
    }

    public function testCreatingIssueBasic(): void
    {
        // We're passing this var as a reference to the closure, so it can
        // pass back the object it received, so we can test it.
        $issueField = null;
        $this->issueService->create(Argument::any())->will(function ($args) use (&$issueField) {
            $issueField = $args[0];

            return (object) ['key' => 'ABC-12'];
        });

        $issue = $this->newIssue();
        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();

        $this->assertEquals('ABC', $issueField->getProjectKey());
        $this->assertEquals('Mytype', $issueField->getIssueType()->name);
        $this->assertEquals('The title', $issueField->summary);
        $this->assertEquals('Lala', $issueField->description);
    }

    public function testMissingField(): void
    {
        $this->issueService->create()->shouldNotBeCalled();

        $this->expectException(\RuntimeException::class);

        $issue = $this->newIssue();
        $issue
            ->setBody('Lala')
            ->ensure();
    }

    public function testCreatingIssueWithKeyLabel(): void
    {
        $this->issueService->create(Argument::any())->willReturn((object) ['key' => 'ABC-13']);

        $this->issueService->search(Argument::any())->willReturn((object) ['total' => 0]);

        $issue = $this->newIssue();
        $issueId = $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->setKeyLabel('banana')
            ->ensure();

        $this->assertEquals('ABC-13', $issueId);

        $this->issueService
            ->search("PROJECT = 'ABC' AND labels IN ('banana') ORDER BY created DESC")
            ->willReturn((object) [
                'total' => 1,
                'issues' => [
                    (object) ['key' => 'ABC-14'],
                ],
            ]);

        $issue = $this->newIssue();
        $issueId = $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->setKeyLabel('banana')
            ->ensure();

        $this->assertEquals('ABC-14', $issueId);

        $this->issueService->create(Argument::any())->shouldBeCalledTimes(1);
    }

    public function testAddingWatchers(): void
    {
        \putenv('JIRA_WATCHERS=user1@example.com,user2@example.com');

        // We're passing this var as a reference to the closure, so it can
        // pass back the object it received, so we can test it.
        $issueField = null;
        $this->issueService->create(Argument::any())->will(function ($args) use (&$issueField) {
            $issueField = $args[0];

            return (object) ['key' => 'ABC-12'];
        });

        $this->userService
            ->findAssignableUsers([
                'query' => 'user1@example.com',
                'project' => 'ABC',
                'maxResults' => 1
            ])
            ->willReturn([(object) ['accountId' => 'abcd']]);
        $this->userService
            ->findAssignableUsers([
                'query' => 'user2@example.com',
                'project' => 'ABC',
                'maxResults' => 1
            ])
            ->willReturn([(object) ['accountId' => '1234']]);

        $this->issueService->addWatcher('ABC-12', 'abcd')->shouldBeCalled();
        $this->issueService->addWatcher('ABC-12', '1234')->shouldBeCalled();

        $issue = $this->newIssue();
        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();
    }
}
