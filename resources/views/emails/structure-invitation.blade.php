<!DOCTYPE html>
<html lang="fr" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">

<head>
    <meta charset="utf-8">
    <meta name="x-apple-disable-message-reformatting">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
    <title>Affiliation à une entreprise</title>
    <link href="https://fonts.googleapis.com/css?family=Montserrat:ital,wght@0,400;0,600;1,400;1,600" rel="stylesheet" media="screen">
    <style>
        .hover-underline:hover {
            text-decoration: underline !important;
        }
        
        @media (max-width: 600px) {
            .sm-w-full {
                width: 100% !important;
            }
            
            .sm-px-24 {
                padding-left: 24px !important;
                padding-right: 24px !important;
            }
            
            .sm-py-32 {
                padding-top: 32px !important;
                padding-bottom: 32px !important;
            }
        }
    </style>
</head>

<body
    style="margin: 0; width: 100%; padding: 0; word-break: break-word; -webkit-font-smoothing: antialiased; background-color: #eceff1;">
    <div role="article" aria-roledescription="email" lang="fr" style="font-family: 'Montserrat', sans-serif; mso-line-height-rule: exactly;">
        <table style="width: 100%; font-family: Montserrat, -apple-system, 'Segoe UI', sans-serif;" cellpadding="0" cellspacing="0" role="presentation">
            <tr>
                <td align="center" style="mso-line-height-rule: exactly; background-color: #eceff1;">
                    <table class="sm-w-full" style="width: 600px;" cellpadding="0" cellspacing="0" role="presentation">
                        <tr>
                            <td class="sm-py-32 sm-px-24" style="padding: 48px; text-align: center;">
                                <a href="{{ config('app.url') }}" style="text-decoration: none;">
                                    <img src="https://example.com/logo.png" width="155" alt="{{ config('app.name') }}" style="max-width: 100%; vertical-align: middle;">
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td align="center" class="sm-px-24">
                                <table style="width: 100%;" cellpadding="0" cellspacing="0" role="presentation">
                                    <tr>
                                        <td class="sm-px-24" style="border-radius: 4px; background-color: #ffffff; padding: 48px; text-align: left; font-size: 16px; line-height: 24px; color: #626262;">
                                            <p style="font-size: 20px; font-weight: 600; margin: 0 0 10px;">
                                                Bonjour,
                                            </p>
                                            
                                            <p style="margin: 0 0 24px;">
                                                <strong>{{ $invitation->inviter->name }}</strong> vous a ajouté à l'entreprise 
                                                <strong>{{ $invitation->structure->name }}</strong> en tant que 
                                                <strong>{{ $invitation->role == 'EMPLOYEE' ? 'Employé' : ($invitation->role == 'MANAGER_ASSISTANT' ? 'Assistant Manager' : 'Observateur') }}</strong>.
                                            </p>

                                            <!-- BLOC POUR LES NOUVEAUX UTILISATEURS -->
                                            @if($user->status == 'CREATED')
                                            <div style="background-color: #d1ecf1; padding: 16px; border-radius: 4px; margin: 0 0 24px; border: 1px solid #bee5eb;">
                                                <p style="margin: 0; color: #0c5460; font-weight: 600;">
                                                    ✅ Votre compte a été créé avec succès !
                                                </p>
                                                <p style="margin: 8px 0 0; color: #0c5460;">
                                                    Vous êtes maintenant membre de l'entreprise <strong>{{ $invitation->structure->name }}</strong>.
                                                    Votre compte est activé et prêt à être utilisé.
                                                </p>
                                            </div>
                                            @endif
                                            
                                            @if($invitation->message)
                                            <div style="background-color: #f8f9fa; padding: 16px; border-radius: 4px; margin: 0 0 24px; border-left: 4px solid #225888;">
                                                <p style="margin: 0; font-style: italic;">
                                                    "{{ $invitation->message }}"
                                                </p>
                                            </div>
                                            @endif
                                            
                                            <div style="background-color: #f1f8ff; padding: 16px; border-radius: 4px; margin: 0 0 24px;">
                                                <p style="margin: 0 0 8px; font-weight: 600;">Détails de votre affiliation :</p>
                                                <table style="width: 100%;" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="padding: 4px 0; width: 40%;">Entreprise :</td>
                                                        <td style="padding: 4px 0; font-weight: 600;">{{ $invitation->structure->name }}</td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding: 4px 0;">Rôle :</td>
                                                        <td style="padding: 4px 0;">{{ $invitation->role == 'EMPLOYEE' ? 'Employé' : ($invitation->role == 'MANAGER_ASSISTANT' ? 'Assistant Manager' : 'Observateur') }}</td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding: 4px 0;">Date d'affiliation :</td>
                                                        <td style="padding: 4px 0;">{{ Carbon\Carbon::now()->format('d/m/Y') }}</td>
                                                    </tr>
                                                </table>
                                            </div>
                                            
                                            <!-- BOUTON POUR SE CONNECTER (UN SEUL) -->
                                            <p style="margin: 0 0 24px;">
                                                Vous pouvez dès maintenant accéder à la plateforme :
                                            </p>
                                            
                                            <table style="width: 100%; text-align: center; margin-bottom: 24px;" cellpadding="0" cellspacing="0" role="presentation">
                                                <tr>
                                                    <td>
                                                        <a href="{{ config('app.url') }}/login" style="display: inline-block; padding: 12px 24px; font-size: 16px; font-weight: 600; color: #ffffff; background-color: #225888; text-decoration: none; border-radius: 4px;">
                                                            Accéder à la plateforme
                                                        </a>
                                                    </td>
                                                </tr>
                                            </table>
                                            
                                            <!-- SUPPRIMER LA SECTION DES LIENS D'ACCEPTATION/REFUS -->
                                            
                                            <p style="margin: 0 0 24px;">
                                                Si vous avez des questions ou rencontrez des problèmes, veuillez nous contacter à l'adresse suivante :
                                                <a href="mailto:{{ config('mail.support_email', 'support@example.com') }}" class="hover-underline" style="color: #225888; text-decoration: none;">{{ config('mail.support_email', 'support@example.com') }}</a>.
                                            </p>
                                            
                                            <p style="margin: 0; color: #626262;">
                                                Cordialement,<br>L'équipe <strong>{{ config('app.name') }}</strong>
                                            </p>
                                            
                                            <table style="width: 100%;" cellpadding="0" cellspacing="0" role="presentation">
                                                <tr>
                                                    <td style="padding-top: 32px; padding-bottom: 32px;">
                                                        <div style="height: 1px; background-color: #eceff1; line-height: 1px;">&zwnj;</div>
                                                    </td>
                                                </tr>
                                            </table>
                                            
                                            <p style="margin: 0; font-size: 12px; color: #999; text-align: center;">
                                                Cet email a été envoyé automatiquement. Merci de ne pas y répondre.
                                                <br>
                                                © {{ date('Y') }} {{ config('app.name') }}. Tous droits réservés.
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="height: 20px;"></td>
                                    </tr>
                                    <tr>
                                        <td style="height: 16px;"></td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>

</html>