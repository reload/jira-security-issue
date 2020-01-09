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
        \putenv('JIRA_ISSUE_TYPE=Mytype');
        \putenv('JIRA_WATCHERS=');

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
        $issue = $this->newIssue();

        // We're passing this var as a reference to the closure, so it can
        // pass back the object it received, so we can test it.
        $issueField = null;
        $this->issueService
            ->create(Argument::any())
            ->will(function ($args) use (&$issueField) {
                $issueField = $args[0];

                return (object) ['key' => 'ABC-12'];
            });

        $this->issueService
            ->addComment(Argument::any(), Argument::any())
            ->willReturn(null);

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
        $issue = $this->newIssue();

        $this->issueService
            ->create()
            ->shouldNotBeCalled();

        $this->expectException(\RuntimeException::class);

        $issue
            ->setBody('Lala')
            ->ensure();
    }

    public function testCreatingIssueWithKeyLabel(): void
    {
        $issue = $this->newIssue();

        $this->issueService
            ->create(Argument::any())
            ->willReturn((object) ['key' => 'ABC-13']);

        $this->issueService
            ->addComment(Argument::any(), Argument::any())
            ->willReturn(null);

        $this->issueService
            ->search(Argument::any())
            ->willReturn((object) ['total' => 0]);

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

        $issue = $this->newIssue();

        $this->issueService
            ->create(Argument::any())
            ->willReturn((object) ['key' => 'ABC-15']);

        $this->issueService
            ->addComment(Argument::any(), Argument::any())
            ->willReturn(null);

        $this->userService
            ->findAssignableUsers([
                'query' => 'user1@example.com',
                'project' => 'ABC',
                'maxResults' => 1
            ])
            ->willReturn([(object) ['name' => 'abcd']]);
        $this->userService
            ->findAssignableUsers([
                'query' => 'user2@example.com',
                'project' => 'ABC',
                'maxResults' => 1
            ])
            ->willReturn([(object) ['name' => '1234']]);

        $this->issueService
            ->addWatcher('ABC-15', 'abcd')
            ->shouldBeCalled();
        $this->issueService
            ->addWatcher('ABC-15', '1234')
            ->shouldBeCalled();

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();
    }

    public function testAddingCommentWithOutWatchers(): void
    {
        $issue = $this->newIssue();

        $this->issueService
            ->create(Argument::any())
            ->willReturn((object) ['key' => 'ABC-16']);
        $this->issueService
            ->addComment('ABC-16', $issue->createComment(JiraSecurityIssue::NO_WATCHERS_TEXT))
            ->shouldBeCalled();

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();

    }

    public function testAddingCommentWithWatchers(): void
    {
        \putenv('JIRA_WATCHERS=user1@example.com,user2@example.com');

        $issue = $this->newIssue();

        $this->issueService
            ->create(Argument::any())
            ->willReturn((object) ['key' => 'ABC-17']);

        $this->issueService
            ->addWatcher(Argument::any(), Argument::any())
            ->willReturn(null);

        $this->userService
            ->findAssignableUsers([
                'query' => 'user1@example.com',
                'project' => 'ABC',
                'maxResults' => 1
            ])
            ->willReturn([(object) ['name' => 'abcd']]);
        $this->userService
            ->findAssignableUsers([
                'query' => 'user2@example.com',
                'project' => 'ABC',
                'maxResults' => 1
            ])
            ->willReturn([(object) ['name' => '1234']]);

        $this->issueService
            ->addComment('ABC-17', $issue->createComment("This issue is being followed by [~abcd] and [~1234]"))
            ->shouldBeCalled();

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();
    }

    public function testMultipleFormatting(): void
    {
        $issue = $this->newIssue();

        $this->assertEquals('one', $issue->formatMultiple(['one']));
        $this->assertEquals('one and two', $issue->formatMultiple(['one', 'two']));
        $this->assertEquals('one, two and three', $issue->formatMultiple(['one', 'two', 'three']));
        $this->assertEquals('one, two, three and four', $issue->formatMultiple(['one', 'two', 'three', 'four']));
    }

    public function testUsersFormatting(): void
    {
        $issue = $this->newIssue();

        $this->assertEquals('[~one] and [~two]', $issue->formatUsers(['one', 'two']));
    }

    public function testQuotedFormatting(): void
    {
        $issue = $this->newIssue();

        $this->assertEquals('"one" and "two"', $issue->formatQuoted(['one', 'two']));
    }

    public function testWatcherNotFound(): void
    {
        \putenv('JIRA_WATCHERS=user1@example.com,notfound@example.com,user2@example.com,notfoundeither@example.com');

        $issue = $this->newIssue();

        $this->issueService
            ->create(Argument::any())
            ->willReturn((object) ['key' => 'ABC-17']);

        $this->issueService
            ->addWatcher(Argument::any(), Argument::any())
            ->willReturn(null);

        $this->userService
            ->findAssignableUsers([
                'query' => 'user1@example.com',
                'project' => 'ABC',
                'maxResults' => 1
            ])
            ->willReturn([(object) ['name' => 'abcd']]);
        $this->userService
            ->findAssignableUsers([
                'query' => 'user2@example.com',
                'project' => 'ABC',
                'maxResults' => 1
            ])
            ->willReturn([(object) ['name' => '1234']]);
        $this->userService
            ->findAssignableUsers([
                'query' => 'notfound@example.com',
                'project' => 'ABC',
                'maxResults' => 1
            ])
            ->willReturn([]);
        $this->userService
            ->findAssignableUsers([
                'query' => 'notfoundeither@example.com',
                'project' => 'ABC',
                'maxResults' => 1
            ])
            ->willReturn([]);

        $this->issueService
            ->addComment('ABC-17', $issue->createComment("This issue is being followed by [~abcd] and [~1234]\n\nCould not find user for \"notfound@example.com\" and \"notfoundeither@example.com\", please check the users listed in JIRA_WATCHERS."))
            ->shouldBeCalled();

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();
    }
}
