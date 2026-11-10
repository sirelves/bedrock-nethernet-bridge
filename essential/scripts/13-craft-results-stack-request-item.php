<?php
// Round 7: no 26.60 os itens de CraftResultsDeprecated vem como StackRequestItem
// (variant varuint + legacy u8 + [identifier string + meta varint] + count int16 + blockRuntimeId varuint + userdata).
// O PocketMine ignora essa acao; no 2225 so lemos no formato novo e descartamos.
// Sintoma: pegar resultado do crafting -> "ItemStackRequestPacket: Not enough bytes left in buffer".
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
$f = 'src/network/mcpe/protocol/types/inventory/stackrequest/DeprecatedCraftingResultsStackRequestAction.php';
$c = file_get_contents("phar://$phar/$f");
patch($c,
	"\t\t\$results = [];\n\t\tfor(\$i = 0, \$len = \$in->getUnsignedVarInt(); \$i < \$len; ++\$i){\n\t\t\t\$results[] = \$in->getItemStackWithoutStackId(false);\n\t\t}\n",
	"\t\t\$results = [];\n".
	"\t\tif(\$in->getProtocolId() >= \\pocketmine\\network\\mcpe\\protocol\\ProtocolInfo::PROTOCOL_1_26_62){\n".
	"\t\t\t//26.60: StackRequestItem por identificador; a acao e so informativa, entao le e descarta\n".
	"\t\t\tfor(\$i = 0, \$len = \$in->getUnsignedVarInt(); \$i < \$len; ++\$i){\n".
	"\t\t\t\t\$variant = \$in->getUnsignedVarInt();\n".
	"\t\t\t\t\$in->getByte(); //legacy variant\n".
	"\t\t\t\tif(\$variant === 1){\n\t\t\t\t\t\$in->getString(); //identifier\n\t\t\t\t\t\$in->getVarInt(); //meta\n\t\t\t\t}elseif(\$variant !== 0){\n".
	"\t\t\t\t\tthrow new \\pocketmine\\network\\mcpe\\protocol\\PacketDecodeException(\"Unknown stack request item descriptor \$variant\");\n\t\t\t\t}\n".
	"\t\t\t\t\$in->getLShort(); //count\n\t\t\t\t\$in->getUnsignedVarInt(); //block runtime id\n\t\t\t\t\$in->getString(); //user data\n".
	"\t\t\t}\n".
	"\t\t}else{\n".
	"\t\t\tfor(\$i = 0, \$len = \$in->getUnsignedVarInt(); \$i < \$len; ++\$i){\n\t\t\t\t\$results[] = \$in->getItemStackWithoutStackId(false);\n\t\t\t}\n".
	"\t\t}\n",
	"DeprecatedCraftingResults: formato 26.60 lido e descartado");
lint($c, $f);
if(($argv[2] ?? '') === '--dry'){ echo "\n=== dry-run OK ===\n"; exit(0); }
$p = new Phar($phar);
$p->startBuffering();
$p[$f] = $c;
$p->stopBuffering();
echo "\n=== phar atualizado (round 7) ===\n";
