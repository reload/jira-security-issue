# Reload Jira Security Issue

This is a small library that can create Jira issues. The main idea is
that it's simple to use and it'll not recreate an issue if the proper
keys are supplied.

Quick example:

``` php
        $issue = new JiraSecurityIssue();

        $issue->setTitle('Issue title')
            ->setBody('The main body');

        $issue->setKeyLabel('some-unique-id');

        echo $issue->ensure();
```

Configuration is set using environment variables, for ease of use in
CI systems.

- `JIRA_TOKEN`: A reference to the repo secret `JiraApiToken` (**REQUIRED**)
- `JIRA_HOST`: The endpoint for your Jira instance, e.g. https://foo.atlassian.net (**REQUIRED**)
- `JIRA_USER`: The ID of the Jira user which is associated with the 'JiraApiToken' secret, eg 'someuser@reload.dk' (**REQUIRED**)
- `JIRA_PROJECT`: The project key for the Jira project where issues should be created, eg `TEST` or `ABC`. (**REQUIRED** if not set in code)
- `JIRA_ISSUE_TYPE`: Type of issue to create, e.g. `Security`. Defaults to `Bug`. (*Optional*)
- `JIRA_WATCHERS`: Jira users to add as watchers to tickets. Separate
  multiple watchers with comma (no spaces). (*Optional*)
