<p>Bonjour {{ $name }},</p>
<p>Votre demande d'identité a été rejetée à l'étape <strong>{{ $stage }}</strong>.</p>
<p>Motifs:</p>
<ul>
@foreach ($reasons as $reason)
<li>{{ $reason }}</li>
@endforeach
</ul>
@if($comments)
<p>Commentaires de l'agent: {{ $comments }}</p>
@endif
<p>Vous pouvez corriger les informations et soumettre à nouveau.</p>
<p>Cordialement,</p>
<p>L'équipe AED</p>
