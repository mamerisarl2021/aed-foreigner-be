#!/usr/bin/env bash
# =====================================================================
#  Provisionne les auth methods Keycloak dans Consul (réalisé UNE fois,
#  après le bootstrap ACL). Idempotent : ré-exécution = erreurs "already
#  exists" sans gravité.
#
#  Prérequis :
#    - Consul durci démarré (docker-compose.secure.yml)
#    - bootstrap ACL effectué :  consul acl bootstrap
#      -> conserver le SecretID (management token) en break-glass !
#    - binaire `consul` disponible (ou: docker exec consul-secure consul ...)
#
#  Variables d'environnement attendues :
#    CONSUL_HTTP_ADDR   (def. https://localhost:8501)
#    CONSUL_HTTP_TOKEN  (le management token issu de `consul acl bootstrap`)
#    CONSUL_CACERT      (def. ./tls/ca.pem)
#    KC_BASE            (def. https://test-oauth.identite-numerique.bj)
#    KC_INFRA_REALM     (def. infra)
#    CONSUL_UI_CLIENT_SECRET  (secret du client Keycloak "consul-ui")
#    CONSUL_UI_URL      (def. https://consul.identite-numerique.bj)
# =====================================================================
set -euo pipefail

export CONSUL_HTTP_ADDR="${CONSUL_HTTP_ADDR:-https://localhost:8501}"
export CONSUL_CACERT="${CONSUL_CACERT:-./tls/ca.pem}"
: "${CONSUL_HTTP_TOKEN:?Fournir le management token (consul acl bootstrap)}"

KC_BASE="${KC_BASE:-https://test-oauth.identite-numerique.bj}"
REALM="${KC_INFRA_REALM:-infra}"
ISSUER="$KC_BASE/realms/$REALM"
JWKS="$ISSUER/protocol/openid-connect/certs"
UI_URL="${CONSUL_UI_URL:-https://consul.identite-numerique.bj}"

echo ">> Issuer Keycloak : $ISSUER"

# ---------------------------------------------------------------------
# 1) Auth method JWT — authentification des SERVICES (client_credentials)
# ---------------------------------------------------------------------
echo ">> Auth method JWT (services) : keycloak-infra-svc"
consul acl auth-method create \
  -type=jwt \
  -name=keycloak-infra-svc \
  -description="Services infra via Keycloak (client_credentials)" \
  -config="{
    \"JWKSURL\": \"$JWKS\",
    \"BoundIssuer\": \"$ISSUER\",
    \"ClaimMappings\": { \"azp\": \"client_id\" },
    \"ListClaimMappings\": { \"realm_access.roles\": \"roles\" }
  }" || echo "   (déjà existant)"

# Binding rule : rôle 'consul-service' -> service identity = clientId
echo ">> Binding rule (services)"
consul acl binding-rule create \
  -method=keycloak-infra-svc \
  -bind-type=service \
  -bind-name='${value.client_id}' \
  -selector='consul-service in list.roles' || echo "   (déjà existante)"

# ---------------------------------------------------------------------
# 2) Politique + rôle pour les OPÉRATEURS humains
# ---------------------------------------------------------------------
echo ">> Politique + rôle opérateur"
consul acl policy create \
  -name operator-policy \
  -rules @provision/operator-policy.hcl || echo "   (déjà existante)"
consul acl role create \
  -name consul-operator \
  -policy-name operator-policy || echo "   (déjà existant)"

# ---------------------------------------------------------------------
# 3) Auth method OIDC — accès des OPÉRATEURS à l'UI / CLI
# ---------------------------------------------------------------------
echo ">> Auth method OIDC (opérateurs) : keycloak-infra-ops"
consul acl auth-method create \
  -type=oidc \
  -name=keycloak-infra-ops \
  -config="{
    \"OIDCDiscoveryURL\": \"$ISSUER\",
    \"OIDCClientID\": \"consul-ui\",
    \"OIDCClientSecret\": \"${CONSUL_UI_CLIENT_SECRET:?Fournir le secret du client consul-ui}\",
    \"AllowedRedirectURIs\": [\"$UI_URL/ui/oidc/callback\"],
    \"ListClaimMappings\": { \"realm_access.roles\": \"roles\" }
  }" || echo "   (déjà existant)"

echo ">> Binding rule (opérateurs)"
consul acl binding-rule create \
  -method=keycloak-infra-ops \
  -bind-type=role \
  -bind-name=consul-operator \
  -selector='consul-admin in list.roles' || echo "   (déjà existante)"

echo ">> Provisioning terminé."
echo "   Services  : consul login -method=keycloak-infra-svc -bearer-token=<JWT Keycloak>"
echo "   Opérateurs: consul login -method=keycloak-infra-ops"
