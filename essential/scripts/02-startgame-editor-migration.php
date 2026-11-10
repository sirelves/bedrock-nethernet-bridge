<?php
$phar = $argv[1] ?? 'Essential.phar';
$path='src/network/mcpe/protocol/types/LevelSettings.php';
$c=file_get_contents("phar://$phar/$path");
$find="\t\t\t\$out->putBool(\$this->exportedFromEditorMode);\n\t\t}\n\t\t\$out->putVarInt(\$this->time);";
$repl="\t\t\t\$out->putBool(\$this->exportedFromEditorMode);\n\t\t}\n\t\tif(\$out->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\$out->putByte(0); //EditorLevelMigrationVersion added in 1.26.60 (proto 2225)\n\t\t}\n\t\t\$out->putVarInt(\$this->time);";
if(substr_count($c,$find)!==1){ fwrite(STDERR,"anchor fail: ".substr_count($c,$find)."\n"); exit(1); }
$c=str_replace($find,$repl,$c);
file_put_contents('/tmp/LevelSettings.php',$c);
echo "patched\n";
