<?php
$phar = $argv[1] ?? 'Essential.phar';
$path='src/network/mcpe/protocol/LevelChunkPacket.php';
$c=file_get_contents("phar://$phar/$path");
$find="\t\t\$out->putString(\$this->extraPayload);\n\t}";
$repl="\t\t\$out->putString(\$this->extraPayload);\n\t\tif(\$out->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\$out->putBool(false); //ClientBiomeUpdate added in 1.26.60 (proto 2225)\n\t\t}\n\t}";
$n=substr_count($c,$find);
if($n!==1){ fwrite(STDERR,"ANCHOR FAIL: $n\n"); exit(1); }
$c=str_replace($find,$repl,$c);
file_put_contents('/tmp/LevelChunkPacket.php',$c);
echo "patched content written\n";
