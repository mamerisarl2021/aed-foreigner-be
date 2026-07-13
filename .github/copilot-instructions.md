# Copilot Instructions for AED-Foreigner API

## Vue d'ensemble de l'architecture
- **Laravel 10** : API structurée autour du framework Laravel, avec une séparation claire entre les couches (app, routes, config, database, tests).
- **Domaines principaux** : Gestion des utilisateurs, des structures, des documents, des signatures, des abonnements et des rôles/permissions (Spatie).
- **Flux de données** : Les contrôleurs dans `app/Http/Controllers` orchestrent les opérations métier, les modèles dans `app/Models` gèrent la persistance, et les middlewares dans `app/Http/Middleware` assurent la sécurité et la validation des requêtes.
- **Sécurité** : Authentification via Laravel Sanctum, gestion fine des rôles et permissions avec Spatie.
- **Exceptions** : Les erreurs d'autorisation sont interceptées et renvoyées en JSON avec un message spécifique (voir `app/Exceptions/Handler.php`).

## Workflows développeur
- **Build & Déploiement** :
  - Utilisation de Docker (voir `dockerfile` et `.github/workflows/build.yml`).
  - Déploiement automatisé via GitHub Actions, incluant build d'image, push sur Docker Hub, et déploiement distant avec migration de la base de données.
  - Commandes clés :
    - `composer update --optimize-autoloader --no-dev`
    - `php artisan migrate --force`
    - Docker compose : `docker compose -f stagging.docker-compose.yml up -d`
- **Tests** :
  - Tests unitaires et fonctionnels dans `tests/Unit` et `tests/Feature`.
  - Exécution : `vendor/bin/phpunit` (config dans `phpunit.xml`).
- **Configuration** :
  - Les paramètres sensibles et d'environnement sont gérés via `.env` et `.env.prod`.
  - Les clés privées/publiques sont copiées dans `storage/` lors du build Docker.

## Conventions et patterns spécifiques
- **Middleware personnalisé** :
  - `ForceJsonResponse` pour forcer le format JSON sur toutes les réponses API.
  - `WithTransaction` pour encapsuler certaines requêtes dans une transaction DB.
  - Middlewares de rôles avancés : `advanced.identity`, `inperson.advanced.identity`.
- **Routes API** :
  - Les routes sont fortement segmentées par rôle (`admin`, `client`, `tech_*`, etc.) et par groupe de middleware.
  - Utilisation de `apiResource` pour les entités principales (documents, attachments, cases, messages, subscriptions).
  - Exemples :
    - `Route::middleware(['auth:sanctum'])->group(...)`
    - `Route::apiResource('documents', DocumentController::class)`
- **Gestion des erreurs** :
  - Les exceptions Spatie sont interceptées et renvoyées avec un message francophone standardisé.

## Intégrations et dépendances
- **Packages principaux** :
  - `spatie/laravel-permission` pour la gestion des rôles/permissions.
  - `laravel/sanctum` pour l'authentification API.
  - `owen-it/laravel-auditing` pour l'audit des actions.
  - `twilio/sdk` pour l'envoi de SMS.
  - `setasign/fpdf` et `setasign/fpdi` pour la gestion des PDF.
- **Docker** :
  - Nginx, Supervisor, PHP 8.1, configuration personnalisée dans `docker/`.
  - Les scripts de build et de run sont dans `docker/run.sh`.

## Fichiers et dossiers clés
- `app/Http/Controllers/` : Logique métier API
- `app/Models/` : Modèles Eloquent
- `app/Http/Middleware/` : Middlewares personnalisés
- `routes/api.php` : Définition des routes API
- `dockerfile`, `docker/` : Build et configuration Docker
- `.github/workflows/` : CI/CD
- `tests/` : Tests unitaires et fonctionnels

---

> **Pour toute nouvelle fonctionnalité, suivez les patterns existants (contrôleur, modèle, middleware, route, test) et respectez la segmentation par rôle et groupe de middleware.**

Merci de signaler toute section incomplète ou peu claire pour itération.
