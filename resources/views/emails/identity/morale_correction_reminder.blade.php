<p>Bonjour {{ $name }},</p>
<p>Rappel : votre demande personne morale ({{ $numero_suivi }}) est toujours à corriger.</p>
@if(!empty($correction_deadline_at))
<p>Échéance : {{ $correction_deadline_at }}.</p>
@endif
<p>Sans correction dans les délais, le dossier sera archivé comme rejeté.</p>
<p>Cordialement,<br/>AED</p>
