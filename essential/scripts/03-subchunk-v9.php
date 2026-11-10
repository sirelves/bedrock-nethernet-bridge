<?php
$phar = $argv[1] ?? 'Essential.phar';
$path='src/network/mcpe/serializer/ChunkSerializer.php';
$c=file_get_contents("phar://$phar/$path");

// Edit 1: serializeSubChunk -> versão 9 + Y para >= 2225
$f1="\tpublic static function serializeSubChunk(SubChunk \$subChunk, BlockTranslator \$blockTranslator, PacketSerializer \$stream, bool \$persistentBlockStates) : void{\n\t\t\$layers = \$subChunk->getBlockLayers();\n\t\t\$stream->putByte(8); //version\n\n\t\t\$stream->putByte(count(\$layers));";
$r1="\tpublic static function serializeSubChunk(SubChunk \$subChunk, BlockTranslator \$blockTranslator, PacketSerializer \$stream, bool \$persistentBlockStates, int \$subChunkY = 0) : void{\n\t\t\$layers = \$subChunk->getBlockLayers();\n\t\tif(\$stream->getProtocolId() >= ProtocolInfo::PROTOCOL_1_26_62){\n\t\t\t\$stream->putByte(9); //subchunk version 9 (1.26.60+)\n\t\t\t\$stream->putByte(count(\$layers));\n\t\t\t\$stream->putByte(\$subChunkY & 0xff); //subchunk Y index\n\t\t}else{\n\t\t\t\$stream->putByte(8); //version\n\t\t\t\$stream->putByte(count(\$layers));\n\t\t}";
if(substr_count($c,$f1)!==1){ fwrite(STDERR,"edit1 anchor fail: ".substr_count($c,$f1)."\n"); exit(1); }
$c=str_replace($f1,$r1,$c);

// Edit 2: passar $y no serializeSubChunks
$f2="\t\t\tself::serializeSubChunk(\n\t\t\t\t\$chunk->getSubChunk(\$y),\n\t\t\t\t\$typeConverter->getBlockTranslator(),\n\t\t\t\t\$subChunkStream,\n\t\t\t\tfalse\n\t\t\t);";
$r2="\t\t\tself::serializeSubChunk(\n\t\t\t\t\$chunk->getSubChunk(\$y),\n\t\t\t\t\$typeConverter->getBlockTranslator(),\n\t\t\t\t\$subChunkStream,\n\t\t\t\tfalse,\n\t\t\t\t\$y\n\t\t\t);";
if(substr_count($c,$f2)!==1){ fwrite(STDERR,"edit2 anchor fail: ".substr_count($c,$f2)."\n"); exit(1); }
$c=str_replace($f2,$r2,$c);

file_put_contents('/tmp/ChunkSerializer.php',$c);
echo "patched\n";
