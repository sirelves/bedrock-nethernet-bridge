<?php
// Aplica no Essential.phar: encryption-skip pro proxy (127.0.0.1), isenção do RakLib block,
// e remove os 4 patches de diagnóstico. Mantém os fixes de protocolo.
$phar = $argv[1] ?? 'Essential.phar';

function patch(string &$c, string $find, string $replace, string $label) : void{
	$n = substr_count($c, $find);
	if($n !== 1){ fwrite(STDERR, "FAIL [$label]: encontrado $n vez(es), esperado 1\n"); exit(1); }
	$c = str_replace($find, $replace, $c);
	echo "  OK  $label\n";
}

$nsPath = 'src/network/mcpe/NetworkSession.php';
$ns = file_get_contents("phar://$phar/$nsPath");

echo "NetworkSession.php:\n";
// 1) remover log OUTPKT
patch($ns,
	"private function sendDataPacketInternal(ClientboundPacket \$packet, bool \$immediate, ?PromiseResolver \$ackReceiptResolver) : bool{\n\t\t\$this->logger->info(\"OUTPKT \" . (new \\ReflectionClass(\$packet))->getShortName());",
	"private function sendDataPacketInternal(ClientboundPacket \$packet, bool \$immediate, ?PromiseResolver \$ackReceiptResolver) : bool{",
	"remove OUTPKT log");
// 2) restaurar flush condicional (tira o flush-por-pacote)
patch($ns,
	"\t\t\t\$this->flushGamePacketQueue(); //DIAG: flush per packet",
	"\t\t\tif(\$immediate){\n\t\t\t\t\$this->flushGamePacketQueue();\n\t\t\t}",
	"restore conditional flush");
// 3) remover log INPKT
patch($ns,
	"public function handleDataPacket(Packet \$packet, string \$buffer) : void{\n\t\t\$this->logger->info(\"INPKT \" . (new \\ReflectionClass(\$packet))->getShortName());",
	"public function handleDataPacket(Packet \$packet, string \$buffer) : void{",
	"remove INPKT log");
// 4) remover log SENDCHUNK
patch($ns,
	"private function sendChunkPacket(string \$chunkPacket, \\Closure \$onCompletion, World \$world) : void{\n\t\t\$this->logger->info(\"SENDCHUNK \" . strlen(\$chunkPacket) . \"b\");",
	"private function sendChunkPacket(string \$chunkPacket, \\Closure \$onCompletion, World \$world) : void{",
	"remove SENDCHUNK log");
// 5) ENCRYPTION-SKIP: sessões vindas do proxy (127.0.0.1) pulam o AES do MC (ja cifradas por DTLS)
patch($ns,
	"\t\tif(EncryptionContext::\$ENABLED){",
	"\t\tif(EncryptionContext::\$ENABLED && \$this->ip !== '127.0.0.1'){",
	"encryption-skip 127.0.0.1");

// lint NetworkSession
file_put_contents('/tmp/ns_check.php', $ns);
exec('php -l /tmp/ns_check.php 2>&1', $o1, $r1);
if($r1 !== 0){ fwrite(STDERR, "LINT FAIL NetworkSession:\n".implode("\n",$o1)."\n"); exit(1); }

$rlPath = 'src/network/mcpe/raklib/RakLibInterface.php';
$rl = file_get_contents("phar://$phar/$rlPath");
echo "RakLibInterface.php:\n";
// 6) isentar 127.0.0.1 do block de 5s pos-bad-packet
patch($rl,
	"\t\t\t\t\$this->interface->blockAddress(\$address, 5);",
	"\t\t\t\tif(\$address !== '127.0.0.1'){ \$this->interface->blockAddress(\$address, 5); }",
	"raklib block exempt 127.0.0.1");
file_put_contents('/tmp/rl_check.php', $rl);
exec('php -l /tmp/rl_check.php 2>&1', $o2, $r2);
if($r2 !== 0){ fwrite(STDERR, "LINT FAIL RakLibInterface:\n".implode("\n",$o2)."\n"); exit(1); }

// grava tudo no phar
$p = new Phar($phar);
$p->startBuffering();
$p[$nsPath] = $ns;
$p[$rlPath] = $rl;
$p->stopBuffering();
echo "\n=== phar atualizado (NetworkSession + RakLibInterface) ===\n";
