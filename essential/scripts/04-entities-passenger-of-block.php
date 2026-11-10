<?php
$phar = $argv[1] ?? 'Essential.phar';
$opt="if(\$out->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\$out->putBool(false); //PassengerOfBlock (1.26.60+)\n\t\t}";

// AddActor: no final
$p1='src/network/mcpe/protocol/AddActorPacket.php';
$c1=file_get_contents("phar://$phar/$p1");
$f1="\t\tforeach(\$this->links as \$link){\n\t\t\t\$out->putEntityLink(\$link);\n\t\t}\n\t}";
$r1="\t\tforeach(\$this->links as \$link){\n\t\t\t\$out->putEntityLink(\$link);\n\t\t}\n\t\t$opt\n\t}";
if(substr_count($c1,$f1)!==1){ fwrite(STDERR,"AddActor anchor fail: ".substr_count($c1,$f1)."\n"); exit(1); }
$c1=str_replace($f1,$r1,$c1);

// AddPlayer: antes do deviceId
$p2='src/network/mcpe/protocol/AddPlayerPacket.php';
$c2=file_get_contents("phar://$phar/$p2");
$f2="\t\t}\n\n\t\t\$out->putString(\$this->deviceId);";
$r2="\t\t}\n\n\t\t$opt\n\t\t\$out->putString(\$this->deviceId);";
if(substr_count($c2,$f2)!==1){ fwrite(STDERR,"AddPlayer anchor fail: ".substr_count($c2,$f2)."\n"); exit(1); }
$c2=str_replace($f2,$r2,$c2);

file_put_contents('/tmp/AddActorPacket.php',$c1);
file_put_contents('/tmp/AddPlayerPacket.php',$c2);
echo "patched\n";
