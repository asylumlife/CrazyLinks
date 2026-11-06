# Security policy

## Reporting a vulnerability

Please report vulnerabilities privately. Do not open a public issue.

Use GitHub's private vulnerability reporting: on the repository page go to the **Security** tab and choose **Report a vulnerability** (or use [Security advisories](https://github.com/asylumlife/CrazyLinks/security/advisories/new)). Include the plugin version, WordPress and PHP versions, steps to reproduce, and what an attacker could do.

You can expect an acknowledgement within a few days. This is a small project run by one maintainer, so fix times vary with severity. Reporters are credited in the advisory unless they prefer not to be.

## Supported versions

Only the latest released version receives security fixes.

| Version | Supported |
|---|---|
| 0.2.x (latest) | Yes |
| Older | No |

## Scope

In scope: the plugin code in `crazylinks/`, including its REST routes, click redirect, subscriber handling and AI/MCP abilities. Out of scope: vulnerabilities in WordPress core, in the MCP Adapter or AI provider plugins, or in third-party services, which should be reported to their own maintainers.
