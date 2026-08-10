# =====================================================================
#  Consul — configuration DURCIE (staging / production)
#  NE PAS utiliser avec le mode -dev. Pour le local, utiliser
#  docker-compose.yml (mode -dev, sans sécurité).
# =====================================================================

datacenter = "asin"
data_dir   = "/consul/data"
node_name  = "consul-1"

server           = true
bootstrap_expect = 1          # 3 (ou 5) en production pour la HA
ui_config { enabled = true }

client_addr = "0.0.0.0"
bind_addr   = "0.0.0.0"

# --- Multi-nœuds (production) : décommenter et lister les pairs ---
# retry_join = ["consul-2", "consul-3"]

# ---------------------------------------------------------------------
#  ACL — tout est refusé par défaut ; chaque appel nécessite un token
# ---------------------------------------------------------------------
acl {
  enabled                  = true
  default_policy           = "deny"
  enable_token_persistence = true
  down_policy              = "extend-cache"
}

# ---------------------------------------------------------------------
#  Chiffrement gossip — générer la clé puis la coller ici :
#     consul keygen           (ou : openssl rand -base64 32)
#  Ne PAS committer la vraie clé (cf. .gitignore).
# ---------------------------------------------------------------------
encrypt                 = "REMPLACER_PAR_LA_CLE_GOSSIP"
encrypt_verify_incoming = true
encrypt_verify_outgoing = true

# ---------------------------------------------------------------------
#  TLS — certificats fournis par votre PKI, déposés dans ./tls
#  - internal_rpc : mTLS strict entre agents/serveurs
#  - https        : chiffré, mais authentifié par TOKEN ACL (pas par
#                   certificat client) -> verify_incoming = false
# ---------------------------------------------------------------------
tls {
  defaults {
    ca_file         = "/consul/tls/ca.pem"
    cert_file       = "/consul/tls/server.pem"
    key_file        = "/consul/tls/server-key.pem"
    verify_outgoing = true
  }
  internal_rpc {
    verify_incoming        = true
    verify_server_hostname = true
  }
  https {
    verify_incoming = false
  }
}

# ---------------------------------------------------------------------
#  Ports : HTTP en clair DÉSACTIVÉ, API en HTTPS
# ---------------------------------------------------------------------
ports {
  http  = -1
  https = 8501
  dns   = 8600
}
