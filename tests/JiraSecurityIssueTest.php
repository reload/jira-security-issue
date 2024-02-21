<?php

declare(strict_types=1);

namespace Reload;

use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\Issue;
use JiraRestApi\Issue\IssueSearchResult;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\User\User;
use JiraRestApi\User\UserService;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use RuntimeException;

final class JiraSecurityIssueTest extends TestCase
{
    use ProphecyTrait;

    /**
     * Mocked issue service.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy<\JiraRestApi\Issue\IssueService>
     */
    protected ObjectProphecy $issueService;

    /**
     * Mocked user service.
     *
     * @var \Prophecy\Prophecy\ObjectProphecy<\JiraRestApi\User\UserService>
     */
    protected ObjectProphecy $userService;

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

    public function testCreatingIssueBasic(): void
    {
        $issue = $this->newIssue();

        // We're passing this var as a reference to the closure, so it can
        // pass back the object it received, so we can test it.
        $issueField = null;
        $this->issueService
            ->create(Argument::any())
            // phpcs:ignore SlevomatCodingStandard.PHP.DisallowReference.DisallowedInheritingVariableByReference
            ->will(static function ($args) use (&$issueField) {
                $issueField = $args[0];

                $issue = new Issue();
                $issue->key = 'ABC-12';

                return $issue;
            });

        $this->issueService
            ->addComment(Argument::any(), Argument::any())
            ->willReturn(new Comment());

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

        $this->expectException(RuntimeException::class);

        $issue
            ->setBody('Lala')
            ->ensure();
    }

    public function testCreatingIssueWithKeyLabel(): void
    {
        $issue = $this->newIssue();

        $this->issueService
            ->create(Argument::any())
            ->will(static function () {
                $issue = new Issue();
                $issue->key = 'ABC-13';

                return $issue;
            });

        $this->issueService
            ->addComment(Argument::any(), Argument::any())
            ->willReturn(new Comment());

        $this->issueService
            ->search(Argument::any())
            ->will(static function () {
                $searchResult = new IssueSearchResult();
                $searchResult->total = 0;

                return $searchResult;
            });

        $issueId = $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->setKeyLabel('banana')
            ->ensure();

        $this->assertEquals('ABC-13', $issueId);

        $this->issueService
            ->search("PROJECT = 'ABC' AND labels IN ('banana') ORDER BY created DESC")
            ->will(static function () {
                $issue = new Issue();
                $issue->key = 'ABC-14';

                $searchResult = new IssueSearchResult();
                $searchResult->total = 1;
                $searchResult->issues = [$issue];

                return $searchResult;
            });

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
            ->will(static function () {
                $issue = new Issue();
                $issue->key = 'ABC-15';

                return $issue;
            });

        $this->issueService
            ->addComment(Argument::any(), Argument::any())
            ->willReturn(new Comment());

        $this->userService
            ->findAssignableUsers([
                'query' => 'user1@example.com',
                'project' => 'ABC',
                'maxResults' => 1,
            ])
            ->willReturn([new User(['accountId' => 'abcd', 'displayName' => 'efgh'])]);
        $this->userService
            ->findAssignableUsers([
                'query' => 'user2@example.com',
                'project' => 'ABC',
                'maxResults' => 1,
            ])
            ->willReturn([new User(['accountId' => '1234', 'displayName' => '5678'])]);

        $this->issueService
            ->addWatcher(Argument::any(), Argument::any())
            ->willReturn(true);

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
            ->will(static function () {
                $issue = new Issue();
                $issue->key = 'ABC-16';

                return $issue;
            });
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
            ->will(static function () {
                $issue = new Issue();
                $issue->key = 'ABC-17';

                return $issue;
            });

        $this->issueService
            ->addWatcher(Argument::any(), Argument::any())
            ->willReturn(true);

        $this->userService
            ->findAssignableUsers([
                'query' => 'user1@example.com',
                'project' => 'ABC',
                'maxResults' => 1,
            ])
            ->willReturn([new User(['accountId' => 'abcd', 'displayName' => 'efgh'])]);
        $this->userService
            ->findAssignableUsers([
                'query' => 'user2@example.com',
                'project' => 'ABC',
                'maxResults' => 1,
            ])
            ->willReturn([new User(['accountId' => '1234', 'displayName' => '5678'])]);

        $this->issueService
            ->addComment('ABC-17', $issue->createComment("This issue is being followed by efgh and 5678"))
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

        $this->assertEquals(
            'one and two',
            $issue->formatUsers([new User(['displayName' => 'one']), new User(['displayName' => 'two'])]),
        );
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
            ->will(static function () {
                $issue = new Issue();
                $issue->key = 'ABC-17';

                return $issue;
            });

        $this->issueService
            ->addWatcher(Argument::any(), Argument::any())
            ->willReturn(true);

        $this->userService
            ->findAssignableUsers([
                'query' => 'user1@example.com',
                'project' => 'ABC',
                'maxResults' => 1,
            ])
            ->willReturn([new User(['accountId' => 'abcd', 'displayName' => 'efgh'])]);
        $this->userService
            ->findAssignableUsers([
                'query' => 'user2@example.com',
                'project' => 'ABC',
                'maxResults' => 1,
            ])
            ->willReturn([new User(['accountId' => '1234', 'displayName' => '5678'])]);
        $this->userService
            ->findAssignableUsers([
                'query' => 'notfound@example.com',
                'project' => 'ABC',
                'maxResults' => 1,
            ])
            ->willReturn([]);
        $this->userService
            ->findAssignableUsers([
                'query' => 'notfoundeither@example.com',
                'project' => 'ABC',
                'maxResults' => 1,
            ])
            ->willReturn([]);

        $this->issueService
            ->addComment(
                'ABC-17',
                $issue->createComment(
                    "This issue is being followed by efgh and 5678\n\n" .
                    "Could not find user for \"notfound@example.com\" and \"notfoundeither@example.com\"," .
                    " please check the users listed in JIRA_WATCHERS.",
                ),
            )
            ->shouldBeCalled();

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();
    }

    public function testEmptyWatcher(): void
    {
        \putenv('JIRA_WATCHERS= ');

        $issue = $this->newIssue();

        $this->issueService
            ->addComment(Argument::any(), Argument::any())
            ->willReturn(new Comment());

        $this->issueService
            ->create(Argument::any())
            ->will(static function () {
                $issue = new Issue();
                $issue->key = 'ABC-17';

                return $issue;
            });

        $this->userService
            ->findAssignableUsers([
                'query' => ' ',
                'project' => 'ABC',
                'maxResults' => 1,
            ])
            ->willReturn([new User(['accountId' => 'abcd', 'displayName' => 'efgh'])]);

        $this->issueService
            ->addComment(
                'ABC-17',
                $issue->createComment(
                    "No watchers on this issue, remember to notify relevant people.",
                ),
            )
            ->shouldBeCalled();

        $this->issueService->addWatcher(Argument::any(), Argument::any())->shouldNotBeCalled();

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();
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
}
