# Consul durci (ACL + gossip + TLS) — phase infra

Ce dossier contient **deux variantes** de Consul :

| Fichier | Usage | Sécurité |
|---|---|---|
| `docker-compose.yml` | Développement local | Aucune (mode `-dev`) |
| `docker-compose.secure.yml` | Staging / production | ACL + gossip + TLS |

> Le mode `-dev` reste pour le local : les développeurs n'ont besoin ni de Keycloak
> ni de certificats. La variante durcie ci-dessous est destinée aux environnements
> partagés.

## Arborescence

```
docker/consul/
├── docker-compose.yml           # -dev (local, inchangé)
├── docker-compose.secure.yml    # variante durcie
├── config/consul.hcl            # config ACL + gossip + TLS
├── tls/                         # certificats (NON versionnés)
├── provision/
│   ├── auth-methods.sh          # crée les auth methods Keycloak
│   └── operator-policy.hcl      # politique ACL des opérateurs
├── .gitignore                   # exclut les secrets
└── README-securisation.md
```

## Mise en place (staging / prod)

### 1. Réseau partagé
```bash
docker network create mod-id-net   # si pas déjà fait
```

### 2. Clé de chiffrement gossip
```bash
consul keygen           # ou : openssl rand -base64 32
```
Coller la valeur dans `config/consul.hcl` → champ `encrypt`.
**Ne pas committer la vraie clé** (cf. `.gitignore`).

### 3. Certificats TLS
Déposer dans `tls/` : `ca.pem`, `server.pem`, `server-key.pem` (votre PKI).
Pour un test rapide : `consul tls ca create` puis
`consul tls cert create -server -dc asin`.

### 4. Démarrer
```bash
docker compose -f docker-compose.secure.yml up -d
```

### 5. Bootstrap ACL (une seule fois)
```bash
docker exec -it consul-secure consul acl bootstrap
```
⚠️ **Conserver le `SecretID` retourné (management token) en break-glass**, coffré,
hors Keycloak. C'est l'accès d'urgence indépendant de l'IdP.

### 6. Provisionner les auth methods Keycloak
```bash
export CONSUL_HTTP_ADDR=https://localhost:8501
export CONSUL_CACERT=./tls/ca.pem
export CONSUL_HTTP_TOKEN=<management-token-issu-du-bootstrap>
export CONSUL_UI_CLIENT_SECRET=<secret-du-client-keycloak-consul-ui>
bash provision/auth-methods.sh
```
Cela crée :
- `keycloak-infra-svc` (JWT) — authentification des **services** (rôle `consul-service`),
- `keycloak-infra-ops` (OIDC) — accès **opérateurs** à l'UI/CLI (rôle `consul-admin`).

## Côté Keycloak (realm `infra`) — prérequis

- Un client confidentiel **par service** (service account / `client_credentials`),
  `clientId` = nom du service, avec le rôle de realm **`consul-service`**.
- Un client **`consul-ui`** (flow standard) pour l'accès opérateurs, et un rôle
  **`consul-admin`** attribué aux administrateurs.

## Côté services — rappel

Les 6 services migrés tournent en `-dev` par défaut. En staging/prod, ils ajoutent
(via variables d'environnement, sans changer le code) :

```yaml
spring:
  cloud:
    consul:
      port:   ${CONSUL_PORT:8500}     # 8501
      scheme: ${CONSUL_SCHEME:http}   # https
      token:  ${CONSUL_HTTP_TOKEN:}   # token ACL obtenu via Keycloak
      tls:
        certificate-path: ${CONSUL_CACERT:}
```

Le token ACL s'obtient par échange : token Keycloak (`client_credentials`) →
`POST /v1/acl/login` (auth method `keycloak-infra-svc`) → `SecretID`.
Voir `docs/ASIN-Documentation-Consul.docx` (§5–§6).

## Vérification

```bash
export CONSUL_HTTP_ADDR=https://localhost:8501 CONSUL_CACERT=./tls/ca.pem
export CONSUL_HTTP_TOKEN=<token>
consul members                       # le serveur répond en TLS
consul acl auth-method list          # keycloak-infra-svc / -ops présents
```

## Rappels sécurité

- **Break-glass** : garder le management token (Consul) hors Keycloak, coffré.
- **Secrets** : clé gossip, certs et secrets clients ne sont jamais committés.
- **Dépendance** : l'émission de nouveaux tokens dépend de Keycloak — prévoir sa HA.
- Keycloak couvre l'**identité/ACL** ; le **TLS** et le **gossip** sont des couches
  distinctes, configurées ici.
