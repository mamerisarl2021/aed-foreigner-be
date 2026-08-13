<p>Bonjour {{ $name }},</p>
<p>Votre demande d'enrôlement personne morale ({{ $numero_suivi }}) doit être corrigée depuis votre espace.</p>
@if(!empty($reasons))
<p>Motifs :</p>
<ul>
@foreach ($reasons as $reason)
<li>{{ $reason }}</li>
@endforeach
</ul>
@endif
@if(!empty($comments))
<p>Commentaires : {{ $comments }}</p>
@endif
@if(!empty($correction_deadline_at))
<p>Merci de déposer le dossier corrigé avant le {{ $correction_deadline_at }}.</p>
@endif
<p>Cordialement,<br/>AED</p>
