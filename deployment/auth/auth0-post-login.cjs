// Auth0 Post Login Action. Set MCP_CLIENT_ID in the Action's Secrets tab.
exports.onExecutePostLogin = async (event, api) => {
  const clientId = event.secrets.MCP_CLIENT_ID;
  if (!clientId) throw new Error('MCP_CLIENT_ID must be configured');
  if (event.client.client_id !== clientId) return;
  const email = typeof event.user.email === 'string' ? event.user.email.trim().toLowerCase() : '';
  if (event.connection.name !== 'email' || event.connection.strategy !== 'email' ||
      event.user.email_verified !== true || email.length > 100 ||
      !/^[^\s@]+@(hikoterra\.com|pcnzl\.com)$/.test(email)) {
    api.access.deny('A verified hikoterra.com or pcnzl.com email is required.');
    return;
  }
  if (event.transaction?.protocol !== 'oauth2-refresh-token') {
    const query = event.request.query;
    if (query.response_type !== 'code' || query.code_challenge_method !== 'S256' ||
        typeof query.code_challenge !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(query.code_challenge)) {
      api.access.deny('Authorization code with S256 PKCE is required.');
      return;
    }
  }
  const namespace = 'https://conceptpower.ddns.net/projeqtor/';
  api.accessToken.setCustomClaim(`${namespace}email`, email);
  api.accessToken.setCustomClaim(`${namespace}email_verified`, true);
};
