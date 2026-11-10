<?php
// Cadeia de bugs do PlayerAuthInput/InventoryTransaction no 26.60 (quebrar/colocar bloco, usar item).
// Todos gated >= 2225.
$phar = $argv[1] ?? 'Essential.phar';

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

// (b) ItemInteractionData: legacy slots viram Optional (bool) no 2225, nao condicionados a requestId!=0
$f1 = 'src/network/mcpe/protocol/types/ItemInteractionData.php';
$c1 = file_get_contents("phar://$phar/$f1");
patch($c1,
	"\t\t\$requestId = \$in->getVarInt();\n\t\t\$requestChangedSlots = [];\n\t\tif(\$requestId !== 0){",
	"\t\t\$requestId = \$in->getVarInt();\n\t\t\$requestChangedSlots = [];\n\t\tif((\$in->getProtocolId() >= \\pocketmine\\network\\mcpe\\protocol\\ProtocolInfo::PROTOCOL_1_26_62) ? \$in->getBool() : (\$requestId !== 0)){",
	"(b) ItemInteractionData: legacy slots Optional");
lint($c1, "ItemInteractionData"); $files[$f1] = $c1;

// (a) TransactionData::decodeAuthInput: no 2225, depois das actions, ler o corpo (decodeData)
$f2 = 'src/network/mcpe/protocol/types/inventory/TransactionData.php';
$c2 = file_get_contents("phar://$phar/$f2");
patch($c2,
	"\t\t\tfor(\$i = 0; \$i < \$actionCount; ++\$i){\n\t\t\t\t\$this->actions[] = (new NetworkInventoryAction())->readAuthInput(\$stream);\n\t\t\t}\n\t\t\treturn;",
	"\t\t\tfor(\$i = 0; \$i < \$actionCount; ++\$i){\n\t\t\t\t\$this->actions[] = (new NetworkInventoryAction())->readAuthInput(\$stream);\n\t\t\t}\n\t\t\tif(\$stream->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\t\$this->decodeData(\$stream);\n\t\t\t}\n\t\t\treturn;",
	"(a) decodeAuthInput: ler corpo no 2225");
lint($c2, "TransactionData"); $files[$f2] = $c2;

// (c) UseItemTransactionData: campo hand (u8) depois do hotbarSlot, no 2225 (decode + encode + propriedade)
$f3 = 'src/network/mcpe/protocol/types/inventory/UseItemTransactionData.php';
$c3 = file_get_contents("phar://$phar/$f3");
patch($c3,
	"\tprivate int \$hotbarSlot;\n\tprivate ItemStackWrapper \$itemInHand;",
	"\tprivate int \$hotbarSlot;\n\tprivate int \$hand = 0;\n\tprivate ItemStackWrapper \$itemInHand;",
	"(c) prop hand");
patch($c3,
	"\t\t\$this->hotbarSlot = \$stream->getVarInt();\n\t\t\$this->itemInHand = \$is2630",
	"\t\t\$this->hotbarSlot = \$stream->getVarInt();\n\t\tif(\$stream->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\$this->hand = \$stream->getByte();\n\t\t}\n\t\t\$this->itemInHand = \$is2630",
	"(c) decode hand");
patch($c3,
	"\t\t\$stream->putVarInt(\$this->hotbarSlot);\n\t\tif(\$is2630){\n\t\t\t\$stream->putNetworkItemStackDescriptor(\$this->itemInHand);",
	"\t\t\$stream->putVarInt(\$this->hotbarSlot);\n\t\tif(\$stream->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\$stream->putByte(\$this->hand);\n\t\t}\n\t\tif(\$is2630){\n\t\t\t\$stream->putNetworkItemStackDescriptor(\$this->itemInHand);",
	"(c) encode hand");
lint($c3, "UseItemTransactionData"); $files[$f3] = $c3;

// (d) NetworkInventoryAction::readAuthInput: no 2225, bool UNICO (nao duplo) pra windowId e sourceFlags
$f4 = 'src/network/mcpe/protocol/types/inventory/NetworkInventoryAction.php';
$c4 = file_get_contents("phar://$phar/$f4");
patch($c4,
	"\t\t\t\$this->windowId = \$packet->readOptional(fn() => \$packet->readOptional(fn() => Binary::signByte(\$packet->getByte())));\n\t\t\t\$this->sourceFlags = \$packet->readOptional(fn() => \$packet->readOptional(fn() => \$packet->getUnsignedVarInt()));",
	"\t\t\tif(\$packet->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\t\$this->windowId = \$packet->readOptional(fn() => Binary::signByte(\$packet->getByte()));\n\t\t\t\t\$this->sourceFlags = \$packet->readOptional(fn() => \$packet->getUnsignedVarInt());\n\t\t\t}else{\n\t\t\t\t\$this->windowId = \$packet->readOptional(fn() => \$packet->readOptional(fn() => Binary::signByte(\$packet->getByte())));\n\t\t\t\t\$this->sourceFlags = \$packet->readOptional(fn() => \$packet->readOptional(fn() => \$packet->getUnsignedVarInt()));\n\t\t\t}",
	"(d) readAuthInput: bool unico no 2225");
lint($c4, "NetworkInventoryAction"); $files[$f4] = $c4;

$p = new Phar($phar);
$p->startBuffering();
foreach($files as $path => $content){ $p[$path] = $content; }
$p->stopBuffering();
echo "\n=== phar atualizado (cadeia de interacao: 4 arquivos) ===\n";
