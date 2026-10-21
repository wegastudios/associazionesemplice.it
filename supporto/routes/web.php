<?php

// Un solo codice, due ruoli: le rotte web sono quelle del ruolo configurato (APP_ROLE, vedi config/support.php).
require base_path('routes/'.config('support.role').'.php');
