<?php

declare(strict_types=1);

namespace Reload;

use JiraRestApi\Issue\Comment;
use JiraRestApi\Issue\Issue;
use JiraRestApi\Issue\IssueField;
use JiraRestApi\Issue\IssueSearchResult;
use JiraRestApi\Issue\IssueService;
use JiraRestApi\User\User;
use JiraRestApi\User\UserService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JiraSecurityIssueTest extends TestCase
{
    public function setUp(): void
    {
        \putenv('JIRA_HOST=https://localhost');
        \putenv('JIRA_USER=user');
        \putenv('JIRA_TOKEN=pass');
        \putenv('JIRA_PROJECT=ABC');
        \putenv('JIRA_ISSUE_TYPE=Mytype');
        \putenv('JIRA_WATCHERS=');
    }

    public function testCreatingIssueBasic(): void
    {
        $issueService = $this->createMock(IssueService::class);
        $issue = $this->newIssue($issueService);

        $issueField = null;
        $issueService
            ->expects($this->once())
            ->method('create')
            // phpcs:ignore SlevomatCodingStandard.PHP.DisallowReference.DisallowedInheritingVariableByReference
            ->willReturnCallback(static function (IssueField $field) use (&$issueField): Issue {
                $issueField = $field;

                return self::newJiraIssue('ABC-12');
            });

        $issueService
            ->expects($this->once())
            ->method('addComment')
            ->willReturn(new Comment());

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();

        $this->assertInstanceOf(IssueField::class, $issueField);
        $this->assertEquals('ABC', $issueField->getProjectKey());
        $this->assertEquals('Mytype', $issueField->getIssueType()->name);
        $this->assertEquals('The title', $issueField->summary);
        $this->assertEquals('Lala', $issueField->description);
    }

    public function testMissingField(): void
    {
        $issueService = $this->createMock(IssueService::class);
        $issue = $this->newIssue($issueService);

        $issueService
            ->expects($this->never())
            ->method('create');

        $this->expectException(RuntimeException::class);

        $issue
            ->setBody('Lala')
            ->ensure();
    }

    public function testCreatingIssueWithKeyLabel(): void
    {
        $issueService = $this->createMock(IssueService::class);

        $notFound = new IssueSearchResult();
        $notFound->issues = [];

        $found = new IssueSearchResult();
        $found->issues = [self::newJiraIssue('ABC-14')];

        // The first search finds nothing, so the issue is created. The
        // second search finds an existing issue, so nothing is created.
        $issueService
            ->expects($this->exactly(2))
            ->method('search')
            ->with("PROJECT = 'ABC' AND labels IN ('banana') ORDER BY created DESC")
            ->willReturnOnConsecutiveCalls($notFound, $found);

        $issueService
            ->expects($this->once())
            ->method('create')
            ->willReturn(self::newJiraIssue('ABC-13'));

        $issueService
            ->expects($this->once())
            ->method('addComment')
            ->willReturn(new Comment());

        $issueId = $this->newIssue($issueService)
            ->setTitle('The title')
            ->setBody('Lala')
            ->setKeyLabel('banana')
            ->ensure();

        $this->assertEquals('ABC-13', $issueId);

        $issueId = $this->newIssue($issueService)
            ->setTitle('The title')
            ->setBody('Lala')
            ->setKeyLabel('banana')
            ->ensure();

        $this->assertEquals('ABC-14', $issueId);
    }

    public function testAddingWatchers(): void
    {
        \putenv('JIRA_WATCHERS=user1@example.com,user2@example.com');

        $issueService = $this->createMock(IssueService::class);
        $issue = $this->newIssue($issueService, $this->stubUserService([
            'user1@example.com' => ['accountId' => 'abcd', 'displayName' => 'efgh'],
            'user2@example.com' => ['accountId' => '1234', 'displayName' => '5678'],
        ]));

        $issueService
            ->method('create')
            ->willReturn(self::newJiraIssue('ABC-15'));

        $issueService
            ->method('addComment')
            ->willReturn(new Comment());

        $watchers = [];
        $issueService
            ->expects($this->exactly(2))
            ->method('addWatcher')
            // phpcs:ignore SlevomatCodingStandard.PHP.DisallowReference.DisallowedInheritingVariableByReference
            ->willReturnCallback(static function (string $issueKey, string $accountId) use (&$watchers): bool {
                $watchers[] = [$issueKey, $accountId];

                return true;
            });

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();

        $this->assertSame([['ABC-15', 'abcd'], ['ABC-15', '1234']], $watchers);
    }

    public function testAddingCommentWithOutWatchers(): void
    {
        $issueService = $this->createMock(IssueService::class);
        $issue = $this->newIssue($issueService);

        $issueService
            ->method('create')
            ->willReturn(self::newJiraIssue('ABC-16'));

        $issueService
            ->expects($this->once())
            ->method('addComment')
            ->with('ABC-16', $issue->createComment(JiraSecurityIssue::NO_WATCHERS_TEXT))
            ->willReturn(new Comment());

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();
    }

    public function testAddingCommentWithWatchers(): void
    {
        \putenv('JIRA_WATCHERS=user1@example.com,user2@example.com');

        $issueService = $this->createMock(IssueService::class);
        $issue = $this->newIssue($issueService, $this->stubUserService([
            'user1@example.com' => ['accountId' => 'abcd', 'displayName' => 'efgh'],
            'user2@example.com' => ['accountId' => '1234', 'displayName' => '5678'],
        ]));

        $issueService
            ->method('create')
            ->willReturn(self::newJiraIssue('ABC-17'));

        $issueService
            ->method('addWatcher')
            ->willReturn(true);

        $issueService
            ->expects($this->once())
            ->method('addComment')
            ->with('ABC-17', $issue->createComment("This issue is being followed by efgh and 5678"))
            ->willReturn(new Comment());

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();
    }

    public function testMultipleFormatting(): void
    {
        $issue = $this->newIssue($this->createStub(IssueService::class));

        $this->assertEquals('one', $issue->formatMultiple(['one']));
        $this->assertEquals('one and two', $issue->formatMultiple(['one', 'two']));
        $this->assertEquals('one, two and three', $issue->formatMultiple(['one', 'two', 'three']));
        $this->assertEquals('one, two, three and four', $issue->formatMultiple(['one', 'two', 'three', 'four']));
    }

    public function testUsersFormatting(): void
    {
        $issue = $this->newIssue($this->createStub(IssueService::class));

        $this->assertEquals(
            'one and two',
            $issue->formatUsers([new User(['displayName' => 'one']), new User(['displayName' => 'two'])]),
        );
    }

    public function testQuotedFormatting(): void
    {
        $issue = $this->newIssue($this->createStub(IssueService::class));

        $this->assertEquals('"one" and "two"', $issue->formatQuoted(['one', 'two']));
    }

    public function testWatcherNotFound(): void
    {
        \putenv('JIRA_WATCHERS=user1@example.com,notfound@example.com,user2@example.com,notfoundeither@example.com');

        $issueService = $this->createMock(IssueService::class);
        $issue = $this->newIssue($issueService, $this->stubUserService([
            'user1@example.com' => ['accountId' => 'abcd', 'displayName' => 'efgh'],
            'user2@example.com' => ['accountId' => '1234', 'displayName' => '5678'],
        ]));

        $issueService
            ->method('create')
            ->willReturn(self::newJiraIssue('ABC-17'));

        $issueService
            ->method('addWatcher')
            ->willReturn(true);

        $issueService
            ->expects($this->once())
            ->method('addComment')
            ->with(
                'ABC-17',
                $issue->createComment(
                    "This issue is being followed by efgh and 5678\n\n" .
                    "Could not find user for \"notfound@example.com\" and \"notfoundeither@example.com\"," .
                    " please check the users listed in JIRA_WATCHERS.",
                ),
            )
            ->willReturn(new Comment());

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();
    }

    public function testEmptyWatcher(): void
    {
        \putenv('JIRA_WATCHERS= ');

        $issueService = $this->createMock(IssueService::class);
        $issue = $this->newIssue($issueService, $this->stubUserService([
            ' ' => ['accountId' => 'abcd', 'displayName' => 'efgh'],
        ]));

        $issueService
            ->method('create')
            ->willReturn(self::newJiraIssue('ABC-17'));

        $issueService
            ->expects($this->once())
            ->method('addComment')
            ->with(
                'ABC-17',
                $issue->createComment(
                    "No watchers on this issue, remember to notify relevant people.",
                ),
            )
            ->willReturn(new Comment());

        $issueService
            ->expects($this->never())
            ->method('addWatcher');

        $issue
            ->setTitle('The title')
            ->setBody('Lala')
            ->ensure();
    }

    /**
     * Create a new JiraSecurityIssue that uses the given services.
     */
    protected function newIssue(IssueService $issueService, ?UserService $userService = null): JiraSecurityIssue
    {
        $issue = new JiraSecurityIssue();
        $issue->setIssueService($issueService);
        $issue->setUserService($userService ?? $this->stubUserService());

        return $issue;
    }

    /**
     * Create a user service that finds the given users in the ABC project.
     *
     * @param array<string, array<string, string>> $users
     *   User data keyed by the query that finds the user.
     */
    protected function stubUserService(array $users = []): UserService
    {
        $userService = $this->createStub(UserService::class);
        $userService
            ->method('findAssignableUsers')
            ->willReturnCallback(static function (array $params) use ($users): array {
                $query = $params['query'] ?? null;

                if (
                    !\is_string($query)
                    || !isset($users[$query])
                    || $params !== ['query' => $query, 'project' => 'ABC', 'maxResults' => 1]
                ) {
                    return [];
                }

                return [new User($users[$query])];
            });

        return $userService;
    }

    protected static function newJiraIssue(string $key): Issue
    {
        $issue = new Issue();
        $issue->key = $key;

        return $issue;
    }
}
