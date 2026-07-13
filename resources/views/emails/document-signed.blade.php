<!DOCTYPE html>
<html lang="fr" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">

<head>
    <meta charset="utf-8">
    <meta name="x-apple-disable-message-reformatting">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
    <title>Document signé</title>
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
                                    <img src="https://example.com/logo.png" width="155" alt="Votre Application" style="max-width: 100%; vertical-align: middle;">
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
                                                Le document intitulé <strong>{{ $documentTitle }}</strong> a été signé par :
                                                <strong>{{ $signerName }}</strong>.
                                            </p>
                                            <p style="margin: 0 0 24px;">
                                                Vous pouvez désormais accéder au document signé depuis votre espace personnel.
                                            </p>
                                            <table style="width: 100%; text-align: center; margin-bottom: 24px;" cellpadding="0" cellspacing="0" role="presentation">
                                                <tr>
                                                    <td>
                                                        <a href="{{ route('documents.show', ['document' => $signature->document->id]) }}" style="display: inline-block; padding: 12px 24px; font-size: 16px; font-weight: 600; color: #ffffff; background-color: #225888; text-decoration: none; border-radius: 4px;">
                                                            Voir le document
                                                        </a>
                                                    </td>
                                                </tr>
                                            </table>
                                            <p style="margin: 0 0 24px;">
                                                Si vous avez des questions, n'hésitez pas à nous contacter à l'adresse suivante :
                                                <a href="mailto:contact@example.com" class="hover-underline" style="color: #225888; text-decoration: none;">contact@example.com</a>.
                                            </p>
                                            <p style="margin: 0; color: #626262;">
                                                Merci,<br>L'équipe de Votre Application
                                            </p>
                                            <table style="width: 100%;" cellpadding="0" cellspacing="0" role="presentation">
                                                <tr>
                                                    <td style="padding-top: 32px; padding-bottom: 32px;">
                                                        <div style="height: 1px; background-color: #eceff1; line-height: 1px;">&zwnj;</div>
                                                    </td>
                                                </tr>
                                            </table>
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
