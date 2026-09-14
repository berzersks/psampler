package main

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

/*
Benchmark equivalente ao PHP:

1. STRING:

	accumulator = accumulator + pcm

	for len(accumulator) >= frameBytes {
		frame = copy(accumulator[:frameBytes])
		accumulator = copy(accumulator[frameBytes:])
	}

2. BYTEBUFFER:

	buffer.Append(pcm)

	for buffer.Has(frameBytes) {
		frame = buffer.Pop(frameBytes)
	}

Uso:

	go build -o benchmark_buffer benchmark_buffer.go

	./benchmark_buffer

	./benchmark_buffer 100000 1920 320

Argumentos:

	1 = iterations
	2 = frameBytes
	3 = chunkBytes
*/

// ==========================================================
// ByteBuffer
// ==========================================================

type ByteBuffer struct {
	data    []byte
	readPos int
	length  int
}

func NewByteBuffer(initialCapacity int) *ByteBuffer {
	if initialCapacity < 1 {
		initialCapacity = 1
	}

	return &ByteBuffer{
		data: make([]byte, initialCapacity),
	}
}

func (b *ByteBuffer) Length() int {
	return b.length
}

func (b *ByteBuffer) Capacity() int {
	return cap(b.data)
}

func (b *ByteBuffer) Has(n int) bool {
	return n >= 0 && b.length >= n
}

func (b *ByteBuffer) ensureCapacity(extra int) {
	required := b.readPos + b.length + extra

	if required <= len(b.data) {
		return
	}

	/*
		Antes de crescer, tenta reaproveitar o espaço
		que já foi consumido no início do buffer.

		Exemplo:

		XXXXXXXXAAAAAA........
		        ^
		        readPos

		vira:

		AAAAAA................
	*/
	if b.readPos > 0 && b.length+extra <= len(b.data) {
		copy(
			b.data[:b.length],
			b.data[b.readPos:b.readPos+b.length],
		)

		b.readPos = 0
		return
	}

	newCapacity := len(b.data) * 2

	if newCapacity < b.length+extra {
		newCapacity = b.length + extra
	}

	newData := make([]byte, newCapacity)

	copy(
		newData,
		b.data[b.readPos:b.readPos+b.length],
	)

	b.data = newData
	b.readPos = 0
}

func (b *ByteBuffer) Append(data []byte) {
	if len(data) == 0 {
		return
	}

	b.ensureCapacity(len(data))

	writePos := b.readPos + b.length

	copy(
		b.data[writePos:writePos+len(data)],
		data,
	)

	b.length += len(data)
}

func (b *ByteBuffer) Pop(n int) []byte {
	if n < 0 || n > b.length {
		panic("ByteBuffer.Pop(): insufficient data")
	}

	if n == 0 {
		return []byte{}
	}

	/*
		Copia somente o frame que será retornado.

		O restante NÃO é copiado.
	*/
	out := make([]byte, n)

	copy(
		out,
		b.data[b.readPos:b.readPos+n],
	)

	b.readPos += n
	b.length -= n

	if b.length == 0 {
		/*
			Buffer vazio.

			Podemos simplesmente voltar o cursor
			para o início sem mover memória.
		*/
		b.readPos = 0
	}

	return out
}

func (b *ByteBuffer) Peek(n int) []byte {
	if n < 0 || n > b.length {
		panic("ByteBuffer.Peek(): insufficient data")
	}

	out := make([]byte, n)

	copy(
		out,
		b.data[b.readPos:b.readPos+n],
	)

	return out
}

func (b *ByteBuffer) Discard(n int) {
	if n < 0 || n > b.length {
		panic("ByteBuffer.Discard(): insufficient data")
	}

	b.readPos += n
	b.length -= n

	if b.length == 0 {
		b.readPos = 0
	}
}

func (b *ByteBuffer) Clear() {
	b.readPos = 0
	b.length = 0
}

// ==========================================================
// Dados
// ==========================================================

func createPCMChunk(size int) []byte {
	pcm := make([]byte, size)

	for i := range pcm {
		pcm[i] = byte(i)
	}

	return pcm
}

func updateChecksum(
	checksum uint64,
	frame []byte,
) uint64 {
	length := len(frame)

	checksum += uint64(length)

	if length > 0 {
		checksum += uint64(frame[0])
		checksum += uint64(frame[length-1])
	}

	return checksum
}

// ==========================================================
// Resultado
// ==========================================================

type BenchmarkResult struct {
	Elapsed       time.Duration
	Frames        uint64
	Checksum      uint64
	Remaining     int
	RemainingData []byte
	Capacity      int
}

// ==========================================================
// STRING
// ==========================================================

func benchmarkString(
	pcm string,
	iterations int,
	frameBytes int,
) BenchmarkResult {
	accumulator := ""

	var frames uint64
	var checksum uint64

	start := time.Now()

	for i := 0; i < iterations; i++ {
		/*
			Equivalente ao PHP:

			$accumulator .= $pcm;

			Cria uma nova string contendo:

			    acumulador antigo + novo PCM
		*/
		accumulator = accumulator + pcm

		for len(accumulator) >= frameBytes {
			/*
				Equivalente a:

				substr($accumulator, 0, $frameBytes)

				strings.Clone() é importante aqui.

				Sem Clone:

				    accumulator[:frameBytes]

				seria somente uma view da string
				original, diferente do substr PHP.
			*/
			frameString := strings.Clone(
				accumulator[:frameBytes],
			)

			/*
				Equivalente a:

				$accumulator =
				    substr(
				        $accumulator,
				        $frameBytes
				    );

				Forçamos uma NOVA cópia.
			*/
			accumulator = strings.Clone(
				accumulator[frameBytes:],
			)

			checksum = updateChecksum(
				checksum,
				[]byte(frameString),
			)

			frames++
		}
	}

	elapsed := time.Since(start)

	return BenchmarkResult{
		Elapsed:       elapsed,
		Frames:        frames,
		Checksum:      checksum,
		Remaining:     len(accumulator),
		RemainingData: []byte(accumulator),
	}
}

// ==========================================================
// BYTEBUFFER
// ==========================================================

func benchmarkByteBuffer(
	pcm []byte,
	iterations int,
	frameBytes int,
) BenchmarkResult {
	initialCapacity := frameBytes * 4

	if initialCapacity < 4096 {
		initialCapacity = 4096
	}

	buffer := NewByteBuffer(
		initialCapacity,
	)

	var frames uint64
	var checksum uint64

	start := time.Now()

	for i := 0; i < iterations; i++ {
		buffer.Append(pcm)

		for buffer.Has(frameBytes) {
			frame := buffer.Pop(
				frameBytes,
			)

			checksum = updateChecksum(
				checksum,
				frame,
			)

			frames++
		}
	}

	elapsed := time.Since(start)

	var remainingData []byte

	if buffer.Length() > 0 {
		remainingData = buffer.Peek(
			buffer.Length(),
		)
	}

	return BenchmarkResult{
		Elapsed:       elapsed,
		Frames:        frames,
		Checksum:      checksum,
		Remaining:     buffer.Length(),
		RemainingData: remainingData,
		Capacity:      buffer.Capacity(),
	}
}

// ==========================================================
// Warm-up
// ==========================================================

func warmup(
	pcm []byte,
	frameBytes int,
) {
	const iterations = 1000

	benchmarkString(
		string(pcm),
		iterations,
		frameBytes,
	)

	benchmarkByteBuffer(
		pcm,
		iterations,
		frameBytes,
	)
}

// ==========================================================
// Impressão
// ==========================================================

func printResult(
	name string,
	result BenchmarkResult,
	iterations int,
	totalBytes int64,
) {
	elapsed :=
		result.Elapsed.Seconds()

	appendCallsPerSecond :=
		float64(iterations) / elapsed

	inputMiBPerSecond :=
		(float64(totalBytes) / 1048576.0) /
			elapsed

	framesPerSecond :=
		float64(result.Frames) /
			elapsed

	fmt.Printf("\n=== %s ===\n", name)

	fmt.Printf(
		"elapsed: %.9f s\n",
		elapsed,
	)

	fmt.Printf(
		"append calls/s: %.2f\n",
		appendCallsPerSecond,
	)

	fmt.Printf(
		"input MiB/s: %.2f\n",
		inputMiBPerSecond,
	)

	fmt.Printf(
		"frames: %d\n",
		result.Frames,
	)

	fmt.Printf(
		"frames/s: %.2f\n",
		framesPerSecond,
	)

	fmt.Printf(
		"remaining bytes: %d\n",
		result.Remaining,
	)

	fmt.Printf(
		"checksum: %d\n",
		result.Checksum,
	)

	if result.Capacity > 0 {
		fmt.Printf(
			"buffer capacity: %d\n",
			result.Capacity,
		)
	}
}

// ==========================================================
// MAIN
// ==========================================================

func main() {
	iterations := 100000
	frameBytes := 1920
	chunkBytes := 320

	if len(os.Args) > 1 {
		if n, err := strconv.Atoi(os.Args[1]); err == nil {
			iterations = n
		}
	}

	if len(os.Args) > 2 {
		if n, err := strconv.Atoi(os.Args[2]); err == nil {
			frameBytes = n
		}
	}

	if len(os.Args) > 3 {
		if n, err := strconv.Atoi(os.Args[3]); err == nil {
			chunkBytes = n
		}
	}

	if iterations < 1 {
		iterations = 1
	}

	if frameBytes < 1 {
		frameBytes = 1
	}

	if chunkBytes < 1 {
		chunkBytes = 1
	}

	pcm := createPCMChunk(
		chunkBytes,
	)

	totalInputBytes :=
		int64(iterations) *
			int64(chunkBytes)

	expectedFrames :=
		totalInputBytes /
			int64(frameBytes)

	expectedRemaining :=
		totalInputBytes %
			int64(frameBytes)

	fmt.Println(
		"=== ByteBuffer benchmark / Go ===",
	)

	fmt.Printf(
		"iterations: %d\n",
		iterations,
	)

	fmt.Printf(
		"chunk/input: %d bytes\n",
		chunkBytes,
	)

	fmt.Printf(
		"frame: %d bytes\n",
		frameBytes,
	)

	fmt.Printf(
		"total input: %d bytes\n",
		totalInputBytes,
	)

	fmt.Printf(
		"expected frames: %d\n",
		expectedFrames,
	)

	fmt.Printf(
		"expected remaining: %d bytes\n",
		expectedRemaining,
	)

	fmt.Println("\nWarm-up...")

	warmup(
		pcm,
		frameBytes,
	)

	stringResult :=
		benchmarkString(
			string(pcm),
			iterations,
			frameBytes,
		)

	bufferResult :=
		benchmarkByteBuffer(
			pcm,
			iterations,
			frameBytes,
		)

	// ======================================================
	// Validação
	// ======================================================

	fmt.Println("\n=== VALIDACAO ===")

	valid := true

	if stringResult.Frames !=
		bufferResult.Frames {

		fmt.Println(
			"FAIL: numero de frames diferente",
		)

		valid = false
	}

	if stringResult.Checksum !=
		bufferResult.Checksum {

		fmt.Println(
			"FAIL: checksum diferente",
		)

		valid = false
	}

	if stringResult.Remaining !=
		bufferResult.Remaining {

		fmt.Println(
			"FAIL: tamanho restante diferente",
		)

		valid = false
	}

	if string(stringResult.RemainingData) !=
		string(bufferResult.RemainingData) {

		fmt.Println(
			"FAIL: conteudo restante diferente",
		)

		valid = false
	}

	if int64(stringResult.Frames) !=
		expectedFrames {

		fmt.Println(
			"FAIL: quantidade de frames inesperada",
		)

		valid = false
	}

	if int64(stringResult.Remaining) !=
		expectedRemaining {

		fmt.Println(
			"FAIL: quantidade restante inesperada",
		)

		valid = false
	}

	if valid {
		fmt.Println(
			"PASS: resultados equivalentes",
		)
	} else {
		fmt.Println("FAIL")
	}

	// ======================================================
	// Resultados
	// ======================================================

	printResult(
		"GO STRING",
		stringResult,
		iterations,
		totalInputBytes,
	)

	printResult(
		"GO BYTEBUFFER",
		bufferResult,
		iterations,
		totalInputBytes,
	)

	// ======================================================
	// Comparação
	// ======================================================

	stringTime :=
		stringResult.Elapsed.Seconds()

	bufferTime :=
		bufferResult.Elapsed.Seconds()

	speedup :=
		stringTime / bufferTime

	reduction :=
		(1.0 - bufferTime/stringTime) *
			100.0

	fmt.Println(
		"\n=== COMPARACAO ===",
	)

	fmt.Printf(
		"ByteBuffer speedup: %.2fx\n",
		speedup,
	)

	fmt.Printf(
		"tempo reduzido: %.2f%%\n",
		reduction,
	)
}