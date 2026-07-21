<p>Bonjour,</p>
<p>La demande d'enrôlement #{{ $enrollment_id }} ({{ $applicant_email }}) vous a été renvoyée par le responsable de validation pour correction.</p>
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
<p>Merci d'apporter les corrections nécessaires puis de re-soumettre la validation agent.</p>
<p>Cordialement,<br/>AED</p>
