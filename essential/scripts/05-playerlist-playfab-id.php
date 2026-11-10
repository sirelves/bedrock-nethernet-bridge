<?php
// PlayFabID move: na skin -> corpo da PlayerListEntry (1.26.60 / proto 2225). Gated.
$phar = $argv[1] ?? 'Essential.phar';

function patch(string &$c, string $find, string $replace, string $label) : void{
	$n = substr_count($c, $find);
	if($n !== 1){ fwrite(STDERR, "FAIL [$label]: $n vez(es), esperado 1\n"); exit(1); }
	$c = str_replace($find, $replace, $c);
	echo "  OK  $label\n";
}

// 1) PlayerListPacket: PlayFabId no corpo da entry (branch do 2225, 5 tabs), depois do xboxUserId
$plPath = 'src/network/mcpe/protocol/PlayerListPacket.php';
$pl = file_get_contents("phar://$phar/$plPath");
patch($pl,
	"\t\t\t\t\t\$out->putString(\$entry->xboxUserId);\n\t\t\t\t\t\$out->putString(\$entry->platformChatId);",
	"\t\t\t\t\t\$out->putString(\$entry->xboxUserId);\n\t\t\t\t\tif(\$out->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\t\t\t\$out->putString(\$entry->skinData->getPlayFabId());\n\t\t\t\t\t}\n\t\t\t\t\t\$out->putString(\$entry->platformChatId);",
	"PlayerList: PlayFabId no corpo da entry");
file_put_contents('/tmp/pl_check.php', $pl);
exec('php -l /tmp/pl_check.php 2>&1', $o1, $r1);
if($r1!==0){ fwrite(STDERR, "LINT FAIL PlayerList:\n".implode("\n",$o1)."\n"); exit(1); }

// 2) PacketSerializer::putSkin: nao escrever PlayFabId na skin pro 2225
$psPath = 'src/network/mcpe/protocol/serializer/PacketSerializer.php';
$ps = file_get_contents("phar://$phar/$psPath");
patch($ps,
	"\t\tif(\$this->getProtocolId() >= ProtocolInfo::PROTOCOL_1_16_210){\n\t\t\t\$this->putString(\$skin->getPlayFabId());\n\t\t}",
	"\t\tif(\$this->getProtocolId() >= ProtocolInfo::PROTOCOL_1_16_210 && \$this->getProtocolId() < ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\$this->putString(\$skin->getPlayFabId());\n\t\t}",
	"putSkin: pula PlayFabId no 2225");
file_put_contents('/tmp/ps_check.php', $ps);
exec('php -l /tmp/ps_check.php 2>&1', $o2, $r2);
if($r2!==0){ fwrite(STDERR, "LINT FAIL PacketSerializer:\n".implode("\n",$o2)."\n"); exit(1); }

$p = new Phar($phar);
$p->startBuffering();
$p[$plPath] = $pl;
$p[$psPath] = $ps;
$p->stopBuffering();
echo "\n=== phar atualizado (PlayerList + PacketSerializer) ===\n";
