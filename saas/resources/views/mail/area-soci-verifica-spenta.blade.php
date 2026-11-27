@if ($toStaff)
Ciao,

nell'area soci di {{ $orgName }} la verifica in due passaggi di {{ $personName }} è stata spenta {{ $how === 'email' ? 'con il link ricevuto per email (telefono e codici di riserva persi)' : 'dalla segreteria' }}. Le altre sessioni aperte sono state chiuse.

Se non vi risulta, contattate la persona e controllate il registro delle azioni.
@else
Ciao,

la verifica in due passaggi del tuo accesso all'area soci di {{ $orgName }} è stata spenta {{ $how === 'email' ? 'con il link ricevuto per email' : 'dalla segreteria' }}. Per sicurezza abbiamo chiuso gli altri accessi aperti.

Ti consigliamo di riaccenderla subito da «Il mio profilo». Se non l'hai chiesto tu, avvisa la segreteria.
@endif

—
{{ $orgName }}
