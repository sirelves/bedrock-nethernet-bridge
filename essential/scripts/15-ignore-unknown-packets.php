<?php
// Round 9: o cliente 26.60 manda pacotes novos que o Essential nao conhece
// (ex.: 0x166 ServerboundCursorItemDrag ao arrastar item em container). O Essential chutava com
// "Unknown packet received". No 2225 passamos a ignorar pacote desconhecido (log debug), como o BDS.
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
$f = 'src/network/mcpe/NetworkSession.php';
$c = file_get_contents("phar://$phar/$f");
patch($c,
	"\t\t\t\t\tif(\$packet === null){\n\t\t\t\t\t\t\$this->logger->debug(\"Unknown packet: \" . base64_encode(\$buffer));\n\t\t\t\t\t\tthrow new PacketHandlingException(\"Unknown packet received\");\n\t\t\t\t\t}\n",
	"\t\t\t\t\tif(\$packet === null){\n\t\t\t\t\t\t\$this->logger->debug(\"Unknown packet: \" . base64_encode(\$buffer));\n".
	"\t\t\t\t\t\tif(\$this->getProtocolId() >= \\pocketmine\\network\\mcpe\\protocol\\ProtocolInfo::PROTOCOL_1_26_62){\n".
	"\t\t\t\t\t\t\tcontinue; //26.60 tem pacotes novos (ex.: ServerboundCursorItemDrag 0x166): ignorar, como o BDS\n".
	"\t\t\t\t\t\t}\n".
	"\t\t\t\t\t\tthrow new PacketHandlingException(\"Unknown packet received\");\n\t\t\t\t\t}\n",
	"NetworkSession: ignora pacote desconhecido no 2225");
lint($c, $f);
if(($argv[2] ?? '') === '--dry'){ echo "\n=== dry-run OK ===\n"; exit(0); }
$p = new Phar($phar);
$p->startBuffering();
$p[$f] = $c;
$p->stopBuffering();
echo "\n=== phar atualizado (round 9) ===\n";
