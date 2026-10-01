package com.flawededge.projeqtor.auth;

import java.util.ArrayList;
import java.util.List;

import org.keycloak.models.KeycloakSession;
import org.keycloak.protocol.oauth2.cimd.clientpolicy.executor.AbstractClientIdMetadataDocumentExecutorFactory;
import org.keycloak.protocol.oauth2.cimd.clientpolicy.executor.ClientIdMetadataDocumentExecutor;
import org.keycloak.provider.ProviderConfigProperty;
import org.keycloak.services.clientpolicy.executor.ClientPolicyExecutorProvider;

public final class ClaudeClientIdMetadataDocumentExecutorFactory extends AbstractClientIdMetadataDocumentExecutorFactory {
    public static final String PROVIDER_ID = "claude-client-id-metadata-document";

    private static final List<ProviderConfigProperty> CONFIG_PROPERTIES = new ArrayList<>();

    static {
        addCommonConfigProperties(CONFIG_PROPERTIES);
        CONFIG_PROPERTIES.add(new ProviderConfigProperty(
                "only-allow-confidential-client",
                "Only Allow Confidential Client",
                "If enabled, accept only confidential client metadata.",
                ProviderConfigProperty.BOOLEAN_TYPE,
                false));
    }

    @Override
    public ClientPolicyExecutorProvider<ClientIdMetadataDocumentExecutor.Configuration> create(KeycloakSession session) {
        return new ClaudeClientIdMetadataDocumentExecutor(session, providerConfig);
    }

    @Override
    public String getId() {
        return PROVIDER_ID;
    }

    @Override
    public List<ProviderConfigProperty> getConfigProperties() {
        return CONFIG_PROPERTIES;
    }
}
