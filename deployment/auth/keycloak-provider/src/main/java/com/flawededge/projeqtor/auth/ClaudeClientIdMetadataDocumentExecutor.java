package com.flawededge.projeqtor.auth;

import java.net.URI;
import java.util.ArrayList;
import java.util.List;

import org.jboss.logging.Logger;
import org.keycloak.models.KeycloakSession;
import org.keycloak.protocol.oauth2.cimd.clientpolicy.executor.ClientIdMetadataDocumentExecutor;
import org.keycloak.protocol.oauth2.cimd.clientpolicy.executor.ClientIdMetadataDocumentExecutorFactoryProviderConfig;
import org.keycloak.representations.oidc.OIDCClientRepresentation;
import org.keycloak.services.clientpolicy.ClientPolicyException;

public final class ClaudeClientIdMetadataDocumentExecutor extends ClientIdMetadataDocumentExecutor {
    public static final String CLAUDE_CLIENT_ID = "https://claude.ai/oauth/mcp-oauth-client-metadata";
    public static final String CLAUDE_REDIRECT_URI = "https://claude.ai/api/mcp/auth_callback";
    public static final String JWT_AUTHORIZATION_GRANT = "urn:ietf:params:oauth:grant-type:jwt-bearer";

    private static final Logger LOGGER = Logger.getLogger(ClaudeClientIdMetadataDocumentExecutor.class);

    public ClaudeClientIdMetadataDocumentExecutor(
            KeycloakSession session,
            ClientIdMetadataDocumentExecutorFactoryProviderConfig providerConfig) {
        super(session, providerConfig);
    }

    @Override
    public String getProviderId() {
        return ClaudeClientIdMetadataDocumentExecutorFactory.PROVIDER_ID;
    }

    @Override
    protected void validateClientMetadata(
            URI clientIdUri,
            URI redirectUri,
            OIDCClientRepresentation client) throws ClientPolicyException {
        if (isExactClaudePublicClient(clientIdUri, redirectUri, client)) {
            List<String> grants = new ArrayList<>(client.getGrantTypes() == null ? List.of() : client.getGrantTypes());
            if (grants.remove(JWT_AUTHORIZATION_GRANT)) {
                LOGGER.info("Removed Claude confidential-only JWT grant from public CIMD metadata");
                client.setGrantTypes(grants);
            }
        }
        super.validateClientMetadata(clientIdUri, redirectUri, client);
    }

    private static boolean isExactClaudePublicClient(
            URI clientIdUri,
            URI redirectUri,
            OIDCClientRepresentation client) {
        String authenticationMethod = client.getTokenEndpointAuthMethod();
        return CLAUDE_CLIENT_ID.equals(clientIdUri.toString())
                && CLAUDE_REDIRECT_URI.equals(redirectUri.toString())
                && (authenticationMethod == null || "none".equals(authenticationMethod));
    }
}
