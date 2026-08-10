# Politique ACL pour les opérateurs humains (rôle consul-operator).
# Accès large en lecture + gestion des services. À AFFINER selon vos besoins
# (principe du moindre privilège).

node_prefix ""    { policy = "read" }
service_prefix "" { policy = "write" }
key_prefix ""     { policy = "read" }
session_prefix "" { policy = "read" }
agent_prefix ""   { policy = "read" }
