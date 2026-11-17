<?php
require __DIR__ . '/../location_rules.php';
$s=['code_panel'=>'a','changeloc'=>'onchangeloc'];
$t=['code_panel'=>'b','changeloc'=>'onchangeloc','status'=>'active','agent'=>'all'];
function check($ok){if(!$ok){throw new RuntimeException('Location policy regression');}}
check(locationChangeAllowed($s,$t,10,'f'));
foreach (['[]','["c"]','broken'] as $r) {check(!locationChangeAllowed($s+['location_targets'=>$r],$t,10,'f'));}
check(locationChangeAllowed($s+['location_targets'=>'["b"]'],$t,10,'f'));
check(!locationChangeAllowed($s,array_replace($t,['changeloc'=>'offchangeloc']),10,'f'));
check(!locationChangeAllowed(array_replace($s,['changeloc'=>'offchangeloc']),$t,10,'f'));
check(!locationChangeAllowed($s,array_replace($t,['status'=>'disabled']),10,'f'));
check(!locationChangeAllowed($s,array_replace($t,['hide_user'=>'[10]']),10,'f'));
check(!locationChangeAllowed($s,array_replace($t,['agent'=>'n']),10,'f'));
check(!locationChangeAllowed($s,array_replace($t,['code_panel'=>'a']),10,'f'));
check(!locationChangeAllowed($t+['location_targets'=>'[]'],array_replace($s,['status'=>'active']),10));
echo "Location policy checks passed\n";
