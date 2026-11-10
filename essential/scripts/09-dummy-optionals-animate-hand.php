<?php
// Round 3: pacotes que o 26.60/2225 mudou e o Essential ainda serializa no formato 1.26.40.
// Achados pelo validador S->C do nethernet-proxy e por crash reproduzido via ADB. Tudo gated >= 2225.
$phar = $argv[1] ?? 'Essential.phar';
const V = '\\pocketmine\\network\\mcpe\\protocol\\ProtocolInfo::PROTOCOL_1_26_62';

function patch(string &$c, string $find, string $replace, string $label) : void{
	$n = substr_count($c, $find);
	if($n !== 1){ fwrite(STDERR, "FAIL [$label]: $n vez(es), esperado 1\n"); exit(1); }
	$c = str_replace($find, $replace, $c);
	echo "  OK  $label\n";
}
function lint(string $code, string $label) : void{
	$tmp = tempnam(sys_get_temp_dir(), 'lint').'.php';
	file_put_contents($tmp, $code);
	exec('php -l '.escapeshellarg($tmp).' 2>&1', $o, $r);
	unlink($tmp);
	if($r !== 0){ fwrite(STDERR, "LINT FAIL [$label]:\n".implode("\n",$o)."\n"); exit(1); }
}
$files = [];

// 1) AnimatePacket: campo Hand (u8) no fim, novo no 26.60 (gophertunnel packet/animate.go)
$f = 'src/network/mcpe/protocol/AnimatePacket.php';
$c = file_get_contents("phar://$phar/$f");
patch($c, "\tpublic ?string \$swingSource = null;\n", "\tpublic ?string \$swingSource = null;\n\tpublic int \$hand = 0;\n", "Animate: prop hand");
patch($c,
	"\t\t\t\$this->swingSource = \$in->readOptional(\$in->getString(...));\n\t\t}\n",
	"\t\t\t\$this->swingSource = \$in->readOptional(\$in->getString(...));\n\t\t}\n\t\tif(\$in->getProtocolId() >= ".V."){\n\t\t\t\$this->hand = \$in->getByte();\n\t\t}\n",
	"Animate: decode hand");
patch($c,
	"\t\t\t\$out->writeOptional(\$this->swingSource, \$out->putString(...));\n\t\t}\n",
	"\t\t\t\$out->writeOptional(\$this->swingSource, \$out->putString(...));\n\t\t}\n\t\tif(\$out->getProtocolId() >= ".V."){\n\t\t\t\$out->putByte(\$this->hand);\n\t\t}\n",
	"Animate: encode hand");
lint($c, $f); $files[$f] = $c;

// 2) InventoryTransactionPacket: no 2225 nao ha dummy optional em volta do transactionType
$f = 'src/network/mcpe/protocol/InventoryTransactionPacket.php';
$c = file_get_contents("phar://$phar/$f");
patch($c,
	"\t\tif(\$is2630){\n\t\t\t\$in->readDummyOptional();\n\t\t}\n\t\t\$transactionType = \$in->getUnsignedVarInt();\n\t\tif(\$is2630){\n\t\t\t\$in->readDummyOptional();\n\t\t}\n",
	"\t\t\$dummy = \$is2630 && \$in->getProtocolId() < ".V.";\n\t\tif(\$dummy){\n\t\t\t\$in->readDummyOptional();\n\t\t}\n\t\t\$transactionType = \$in->getUnsignedVarInt();\n\t\tif(\$dummy){\n\t\t\t\$in->readDummyOptional();\n\t\t}\n",
	"ITP: decode sem dummy");
patch($c,
	"\t\tif(\$is2630){\n\t\t\t\$out->writeDummyOptional();\n\t\t\t\$out->putUnsignedVarInt(\$this->trData->getTypeId());\n\t\t\t\$out->writeDummyOptional();\n\t\t}else{",
	"\t\tif(\$is2630 && \$out->getProtocolId() < ".V."){\n\t\t\t\$out->writeDummyOptional();\n\t\t\t\$out->putUnsignedVarInt(\$this->trData->getTypeId());\n\t\t\t\$out->writeDummyOptional();\n\t\t}else{",
	"ITP: encode sem dummy");
lint($c, $f); $files[$f] = $c;

// 3) NetworkInventoryAction: no 2225 windowId/sourceFlags sao Optional SIMPLES (gophertunnel InventoryAction.Marshal)
$f = 'src/network/mcpe/protocol/types/inventory/NetworkInventoryAction.php';
$c = file_get_contents("phar://$phar/$f");
patch($c,
	"\t\t\tif(\$packet->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_40){\n\t\t\t\t\$this->windowId = \$packet->readOptional(fn() => \$packet->readOptional(",
	"\t\t\tif(\$packet->getProtocolId() >= ".V."){\n\t\t\t\t\$this->windowId = \$packet->readOptional(fn() => Binary::signByte(\$packet->getByte()));\n\t\t\t\t\$this->sourceFlags = \$packet->readOptional(fn() => \$packet->getUnsignedVarInt());\n\t\t\t}elseif(\$packet->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_40){\n\t\t\t\t\$this->windowId = \$packet->readOptional(fn() => \$packet->readOptional(",
	"NIA: read optional simples");
$w2 = "\$packet->writeOptional(\$this->windowId, fn(int \$windowId) => \$packet->writeOptional(\$windowId, fn(int \$v) => \$packet->putByte(Binary::unsignByte(\$v))));";
$s2 = "\$packet->writeOptional(\$this->sourceFlags, fn(int \$sourceFlags) => \$packet->writeOptional(\$sourceFlags, fn(int \$v) => \$packet->putUnsignedVarInt(\$v)));";
$w1 = "\$packet->writeOptional(\$this->windowId, fn(int \$v) => \$packet->putByte(Binary::unsignByte(\$v)));";
$s1 = "\$packet->writeOptional(\$this->sourceFlags, fn(int \$v) => \$packet->putUnsignedVarInt(\$v));";
foreach(["\t\t\t\t" => "NIA: write", "\t\t\t" => "NIA: writeAuthInput"] as $ind => $label){
	patch($c,
		"\n$ind$w2\n$ind$s2\n",
		"\n{$ind}if(\$packet->getProtocolId() >= ".V."){\n$ind\t$w1\n$ind\t$s1\n$ind}else{\n$ind\t$w2\n$ind\t$s2\n$ind}\n",
		$label." optional simples");
}
lint($c, $f); $files[$f] = $c;

// 4) ItemStackResponseSlotInfo (S->C): stack id e Optional SIMPLES no 2225 (gophertunnel StackResponseSlotInfo)
$f = 'src/network/mcpe/protocol/types/inventory/stackresponse/ItemStackResponseSlotInfo.php';
$c = file_get_contents("phar://$phar/$f");
patch($c,
	"\t\t\t\$itemStackId = \$in->readDoubleOptional(\$in->readServerItemStackId(...));",
	"\t\t\t\$itemStackId = \$in->getProtocolId() >= ".V." ? \$in->readOptional(\$in->readServerItemStackId(...)) : \$in->readDoubleOptional(\$in->readServerItemStackId(...));",
	"ISRSI: read optional simples");
patch($c,
	"\t\t\t\$out->writeDoubleOptional(\$this->itemStackId, \$out->writeServerItemStackId(...));",
	"\t\t\tif(\$out->getProtocolId() >= ".V."){\n\t\t\t\t\$out->writeOptional(\$this->itemStackId, \$out->writeServerItemStackId(...));\n\t\t\t}else{\n\t\t\t\t\$out->writeDoubleOptional(\$this->itemStackId, \$out->writeServerItemStackId(...));\n\t\t\t}",
	"ISRSI: write optional simples");
lint($c, $f); $files[$f] = $c;

// 5) PacketSerializer: o "dummy optional" do 1.26.40 nao existe no 2225. Gatear os helpers cobre
//    tambem os Double* (readDoubleOptional/writeDoubleOptional) e o encoder do PlayerAuthInput.
$f = 'src/network/mcpe/protocol/serializer/PacketSerializer.php';
$c = file_get_contents("phar://$phar/$f");
patch($c,
	"\tpublic function readDummyOptional() : void{\n\t\t\$dummy = \$this->getByte();",
	"\tpublic function readDummyOptional() : void{\n\t\tif(\$this->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\treturn;\n\t\t}\n\t\t\$dummy = \$this->getByte();",
	"PacketSerializer: readDummyOptional no-op no 2225");
patch($c,
	"\tpublic function writeDummyOptional() : void{\n\t\t\$this->putByte(1);",
	"\tpublic function writeDummyOptional() : void{\n\t\tif(\$this->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\treturn;\n\t\t}\n\t\t\$this->putByte(1);",
	"PacketSerializer: writeDummyOptional no-op no 2225");
lint($c, $f); $files[$f] = $c;

if(($argv[2] ?? '') === '--dry'){ echo "\n=== dry-run OK (nada gravado) ===\n"; exit(0); }
$p = new Phar($phar);
$p->startBuffering();
foreach($files as $path => $content){ $p[$path] = $content; }
$p->stopBuffering();
echo "\n=== phar atualizado (round 3: ".count($files)." arquivos) ===\n";
