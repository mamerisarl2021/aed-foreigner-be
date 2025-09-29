<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Activation de votre compte</title>
  <style>
    body { margin:0; font-family: 'Montserrat', sans-serif; background:#f4f6f8; color:#333; }
    .container { max-width:600px; margin:auto; background:#fff; border-radius:8px; padding:32px; }
    .btn { display:inline-block; background:#225888; color:#fff; padding:14px 28px; border-radius:6px; font-weight:600; text-decoration:none; }
    .code { font-size:22px; font-weight:700; letter-spacing:4px; background:#f1f1f1; padding:12px 20px; display:inline-block; border-radius:6px; }
  </style>
</head>
<body>
  <div class="container">
    <h2>Bonjour {{ $user->name }},</h2>
    <p>Votre enrôlement a été effectué avec succès.</p>

    <p>Ensuite, cliquez sur le lien sécurisé ci-dessous pour finaliser l’activation :</p>

    <p style="text-align:center;">
      <a href="{{ $activationUrl }}" class="btn">Activer mon compte</a>
    </p>

    @if(!$hasAccount)
      <p><b>Vous êtes un nouvel utilisateur.</b>  
      Lors de l’activation, il vous sera demandé de <b>choisir un PIN et un mot de passe</b> pour sécuriser votre compte.</p>
    @else
      <p><b>Vous disposez déjà d’un compte.</b>  
      Lors de l’activation, vous pourrez <b>conserver vos identifiants actuels</b> ou <b>mettre à jour votre PIN et votre mot de passe</b> pour renforcer la sécurité.</p>
    @endif

    <p style="margin-top:24px;">⚠️ Pour des raisons de sécurité, ce code OTP et le lien d’activation expirent dans <b>15 minutes</b>.</p>

    <p>Si vous n’êtes pas à l’origine de cette demande, merci de nous contacter immédiatement : 
      <a href="mailto:collabone@qualitycorporate.com">collabone@qualitycorporate.com</a>.
    </p>

    <p style="margin-top:32px;">Cordialement,<br>L’équipe d’enregistrement déléguée</p>
  </div>
</body>
</html>
