<p>Bonjour {{ $name }},</p>
<p>Un agent a demandé une séance de visioconférence concernant votre demande d'enrôlement.</p>
@if(!empty($notes))
<p>Message de l'agent : {{ $notes }}</p>
@endif
<p>Vous serez contacté pour convenir des modalités</p>
<p>Cordialement,<br/>AED</p>
