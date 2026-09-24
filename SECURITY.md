# Security

Report vulnerabilities privately through the repository's GitHub security-advisory interface. Do not open a public issue containing credentials, tokens, internal addresses, or exploit details.

Deployment requirements:

- Expose the MCP endpoint only on a trusted network or behind TLS.
- Give every person a distinct bearer token mapped to their own ProjeQtOr username.
- Mount the principal file and signing key read-only; never bake them into an image.
- Keep the PHP bridge reachable only from the MCP container's private network address.
- Rotate a user's bearer token immediately if it is disclosed.
- Preserve ProjeQtOr authorization and auditing; do not map multiple people to a shared privileged account.
