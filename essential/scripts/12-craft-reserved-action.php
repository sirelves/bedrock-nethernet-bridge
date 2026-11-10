<?php
// Round 6: o 26.60 inseriu StackRequestActionCraftReserved (id 18) depois do CraftLoom.
// NonImplementedDeprecated foi pra 19 e CraftResultsDeprecated pra 20 (gophertunnel item_stack.go).
// Sintoma: pegar o resultado do crafting -> "Unhandled item stack request action type 20".
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
$f = 'src/network/mcpe/protocol/types/inventory/stackrequest/ItemStackRequest.php';
$c = file_get_contents("phar://$phar/$f");
patch($c,
	"\t\t\t\t\$typeId = ItemStackRequestActionType::fromModernTypeId(\$in->getUnsignedVarInt());\n\t\t\t\t\$in->getByte(); // legacy action type ID, retained by the client for compatibility\n",
	"\t\t\t\t\$typeId = ItemStackRequestActionType::fromModernTypeId(\$in->getUnsignedVarInt());\n\t\t\t\t\$in->getByte(); // legacy action type ID, retained by the client for compatibility\n".
	"\t\t\t\tif(\$in->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n".
	"\t\t\t\t\tif(\$typeId === 18){\n".
	"\t\t\t\t\t\t//26.60: CraftReserved (string reservedId + u8 numberOfCrafts), sem equivalente aqui: le e descarta\n".
	"\t\t\t\t\t\t\$in->getString();\n\t\t\t\t\t\t\$in->getByte();\n\t\t\t\t\t\tcontinue;\n".
	"\t\t\t\t\t}\n".
	"\t\t\t\t\tif(\$typeId > 18){\n\t\t\t\t\t\t\$typeId--; //NonImplementedDeprecated 19->18, CraftResultsDeprecated 20->19\n\t\t\t\t\t}\n".
	"\t\t\t\t}\n",
	"ItemStackRequest::read: CraftReserved + shift no 2225");
patch($c,
	"\t\t\t\t\$out->putUnsignedVarInt(ItemStackRequestActionType::toModernTypeId(\$typeId));\n",
	"\t\t\t\t\$modern = ItemStackRequestActionType::toModernTypeId(\$typeId);\n".
	"\t\t\t\tif(\$out->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62 && \$typeId >= ItemStackRequestActionType::CRAFTING_NON_IMPLEMENTED_DEPRECATED_ASK_TY_LAING){\n\t\t\t\t\t\$modern++; //26.60 inseriu CraftReserved antes\n\t\t\t\t}\n".
	"\t\t\t\t\$out->putUnsignedVarInt(\$modern);\n",
	"ItemStackRequest::write: shift no 2225");
lint($c, $f);
if(($argv[2] ?? '') === '--dry'){ echo "\n=== dry-run OK ===\n"; exit(0); }
$p = new Phar($phar);
$p->startBuffering();
$p[$f] = $c;
$p->stopBuffering();
echo "\n=== phar atualizado (round 6) ===\n";
