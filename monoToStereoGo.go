package main

import (
	"fmt"
	"os"
	"runtime"
	"strconv"
	"time"
	"unsafe"
)

func monoToStereo(pcmData string) string {
	pcmLen := len(pcmData)

	if pcmLen == 0 {
		return ""
	}

	if pcmLen%2 != 0 {
		panic("pcmData must contain complete 16-bit samples")
	}

	out := make([]byte, pcmLen*2)

	for srcOffset, dstOffset := 0, 0;
		srcOffset < pcmLen;
		srcOffset, dstOffset = srcOffset+2, dstOffset+4 {

		a := pcmData[srcOffset]
		b := pcmData[srcOffset+1]

		out[dstOffset] = a
		out[dstOffset+1] = b
		out[dstOffset+2] = a
		out[dstOffset+3] = b
	}

	/*
		Converte []byte -> string SEM copiar.

		IMPORTANTE:
		depois disso, out não pode mais ser alterado.
	*/
	result := unsafe.String(
		unsafe.SliceData(out),
		len(out),
	)

	runtime.KeepAlive(out)

	return result
}

func runBenchmark(iterations int, samplesPerFrame int) {
	if iterations < 1 {
		iterations = 1
	}

	if samplesPerFrame < 1 {
		samplesPerFrame = 1
	}

	pcm := make([]byte, samplesPerFrame*2)

	for i := 0; i < samplesPerFrame; i++ {
		pcm[i*2] = 0x01
		pcm[i*2+1] = 0x02
	}

	pcmMono := string(pcm)

	expectedInputBytes := samplesPerFrame * 2
	expectedOutputBytes := expectedInputBytes * 2

	probe := monoToStereo(pcmMono)

	if len(probe) != expectedOutputBytes {
		panic("tamanho de saída inesperado")
	}

	if len(probe) < 4 ||
		probe[0] != 0x01 ||
		probe[1] != 0x02 ||
		probe[2] != 0x01 ||
		probe[3] != 0x02 {

		panic("conversão incorreta")
	}

	warmup := iterations

	if warmup > 1000 {
		warmup = 1000
	}

	var warmChecksum uint64

	for i := 0; i < warmup; i++ {
		stereo := monoToStereo(pcmMono)
		warmChecksum += uint64(len(stereo))
	}

	if warmChecksum == 0 {
		panic("warmup inválido")
	}

	var checksum uint64

	start := time.Now()

	for i := 0; i < iterations; i++ {
		stereo := monoToStereo(pcmMono)
		checksum += uint64(len(stereo))
	}

	elapsed := time.Since(start).Seconds()

	totalInputBytes :=
		float64(expectedInputBytes) *
			float64(iterations)

	totalOutputBytes :=
		float64(expectedOutputBytes) *
			float64(iterations)

	callsPerSecond :=
		float64(iterations) / elapsed

	inputMiBPerSecond :=
		(totalInputBytes / 1048576.0) / elapsed

	outputMiBPerSecond :=
		(totalOutputBytes / 1048576.0) / elapsed

	fmt.Println("=== monoToStereo / Go zero-copy return ===")
	fmt.Printf("iterations: %d\n", iterations)
	fmt.Printf("samples/frame: %d\n", samplesPerFrame)
	fmt.Printf("input/frame: %d bytes\n", expectedInputBytes)
	fmt.Printf("output/frame: %d bytes\n", expectedOutputBytes)
	fmt.Printf("elapsed: %.15f s\n", elapsed)
	fmt.Printf("calls/s: %.15f\n", callsPerSecond)
	fmt.Printf("input MiB/s: %.15f\n", inputMiBPerSecond)
	fmt.Printf("output MiB/s: %.15f\n", outputMiBPerSecond)
	fmt.Printf("checksum: %d\n", checksum)
}

func main() {
	iterations := 50000
	samplesPerFrame := 960

	if len(os.Args) > 1 {
		if n, err := strconv.Atoi(os.Args[1]); err == nil {
			iterations = n
		}
	}

	if len(os.Args) > 2 {
		if n, err := strconv.Atoi(os.Args[2]); err == nil {
			samplesPerFrame = n
		}
	}

	runBenchmark(iterations, samplesPerFrame)
}