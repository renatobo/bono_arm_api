# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.2.x   | :white_check_mark: |

## Security Model

- API clients should authenticate over HTTPS with WordPress Application Passwords.
- Payment reads, member activation, and member deletion use separate capabilities so integrations can receive only the access they require.
- Member deletion also uses WordPress object-level authorization, rejects self-deletion, requires a distinct valid reassignment target, and is unavailable on multisite.
- The v2 payment API excludes payer email and administrative notes in its default `view` context.
- Feature toggles are an additional control and do not replace capability checks.

## Reporting a Vulnerability

Report vulnerabilities privately through the repository's GitHub Security Advisories page. Do not include secrets, personal data, or exploit details in a public issue.
