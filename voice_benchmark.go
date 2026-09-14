package main

import (
	"flag"
	"fmt"
	"hash/crc32"
	"math"
	"os"
	"runtime"
	"strings"
	"time"
)

const (
	checksumModulus          uint64 = 2147483647
	checksumMultiplier       uint64 = 65599
	byteBufferInitialCapacity       = 4096
)

var variableChunkSizes = []int{320, 500, 700, 320, 1000, 160, 1024, 960, 300, 1920}

type ByteBuffer struct {
	data     []byte
	readPos  int
	writePos int
	length   int
}

func NewByteBuffer(initialCapacity int) *ByteBuffer {
	if initialCapacity < 1 {
		panic("ByteBuffer capacity must be positive")
	}

	return &ByteBuffer{data: make([]byte, initialCapacity)}
}

func (b *ByteBuffer) Len() int {
	return b.length
}

func (b *ByteBuffer) Has(n int) bool {
	return n >= 0 && n <= b.length
}

func (b *ByteBuffer) ensureCapacity(additional int) {
	required := b.length + additional
	if required <= len(b.data) {
		return
	}

	newCapacity := len(b.data)
	for newCapacity < required {
		newCapacity *= 2
	}

	newData := make([]byte, newCapacity)
	if b.length > 0 {
		first := minInt(b.length, len(b.data)-b.readPos)
		copy(newData[:first], b.data[b.readPos:b.readPos+first])
		if first < b.length {
			copy(newData[first:b.length], b.data[:b.length-first])
		}
	}

	b.data = newData
	b.readPos = 0
	b.writePos = b.length
}

func (b *ByteBuffer) Append(data string) {
	if len(data) == 0 {
		return
	}

	b.ensureCapacity(len(data))
	first := minInt(len(data), len(b.data)-b.writePos)
	copy(b.data[b.writePos:b.writePos+first], data[:first])
	if first < len(data) {
		copy(b.data[:len(data)-first], data[first:])
	}
	b.writePos = (b.writePos + len(data)) % len(b.data)
	b.length += len(data)
}

func (b *ByteBuffer) Pop(n int) []byte {
	if n < 0 || n > b.length {
		panic("ByteBuffer.Pop: insufficient data")
	}
	if n == 0 {
		return []byte{}
	}

	out := make([]byte, n)
	first := minInt(n, len(b.data)-b.readPos)
	copy(out[:first], b.data[b.readPos:b.readPos+first])
	if first < n {
		copy(out[first:], b.data[:n-first])
	}

	b.readPos = (b.readPos + n) % len(b.data)
	b.length -= n
	if b.length == 0 {
		b.readPos = 0
		b.writePos = 0
	}

	return out
}

func (b *ByteBuffer) Snapshot() []byte {
	out := make([]byte, b.length)
	if b.length == 0 {
		return out
	}

	first := minInt(b.length, len(b.data)-b.readPos)
	copy(out[:first], b.data[b.readPos:b.readPos+first])
	if first < b.length {
		copy(out[first:], b.data[:b.length-first])
	}
	return out
}

func minInt(a, b int) int {
	if a < b {
		return a
	}
	return b
}

func combineChecksum(checksum, value uint64) uint64 {
	return (checksum*checksumMultiplier + value%checksumModulus + 97) % checksumModulus
}

func updateStringChecksum(checksum uint64, frame string) uint64 {
	checksum = combineChecksum(checksum, uint64(len(frame)))
	return combineChecksum(checksum, uint64(crc32.ChecksumIEEE([]byte(frame))))
}

func updateBytesChecksum(checksum uint64, frame []byte) uint64 {
	checksum = combineChecksum(checksum, uint64(len(frame)))
	return combineChecksum(checksum, uint64(crc32.ChecksumIEEE(frame)))
}

// makePCMChunks uses the same formula as voice_benchmark.php.
func makePCMChunks(callID int, chunkSizes []int) []string {
	chunks := make([]string, len(chunkSizes))
	for slot, size := range chunkSizes {
		chunk := make([]byte, size)
		for i := range chunk {
			value := ((callID + 1) * 53) + (slot * 97) + (i * 29) + ((i / 8) * 7)
			chunk[i] = byte(value & 0xff)
		}
		chunks[slot] = string(chunk)
	}
	return chunks
}

type CallResult struct {
	CallID          int
	End             time.Time
	Ticks           uint64
	InputBytes      uint64
	ProcessedBytes  uint64
	Frames          uint64
	Checksum        uint64
	RemainingBytes  uint64
	RemainingData   []byte
	DeadlineMisses  uint64
	DelayTotal      time.Duration
	DelayMax        time.Duration
}

type workerConfig struct {
	mode        string
	runtimeMode string
	ticks       int
	ptime       time.Duration
	frameBytes  int
}

func runCall(
	callID int,
	config workerConfig,
	chunks []string,
	benchmarkStart *time.Time,
	ready chan<- struct{},
	startGate <-chan struct{},
	completed chan<- CallResult,
) {
	// Allocate each call's independent accumulator before the start barrier.
	accumulator := ""
	var buffer *ByteBuffer
	if config.mode == "bytebuffer" {
		buffer = NewByteBuffer(byteBufferInitialCapacity)
	}

	ready <- struct{}{}
	<-startGate

	result := CallResult{
		CallID:   callID,
		Checksum: combineChecksum(0, uint64(callID+1)),
	}

	for tick := 0; tick < config.ticks; tick++ {
		var deadline time.Time
		if config.runtimeMode == "realtime" {
			deadline = benchmarkStart.Add(time.Duration(tick+1) * config.ptime)
			if wait := time.Until(deadline); wait > 0 {
				time.Sleep(wait)
			}

			delay := time.Since(deadline)
			if delay < 0 {
				delay = 0
			}
			result.DelayTotal += delay
			if delay > result.DelayMax {
				result.DelayMax = delay
			}
		}

		pcm := chunks[tick%len(chunks)]
		result.InputBytes += uint64(len(pcm))

		if config.mode == "string" {
			accumulator = accumulator + pcm

			for len(accumulator) >= config.frameBytes {
				// strings.Clone forces the same real frame copy made by PHP substr().
				frame := strings.Clone(accumulator[:config.frameBytes])
				// The unread remainder is also copied, like the second PHP substr().
				accumulator = strings.Clone(accumulator[config.frameBytes:])

				result.Checksum = updateStringChecksum(result.Checksum, frame)
				result.Frames++
				result.ProcessedBytes += uint64(config.frameBytes)
			}
		} else {
			buffer.Append(pcm)

			for buffer.Has(config.frameBytes) {
				frame := buffer.Pop(config.frameBytes)
				result.Checksum = updateBytesChecksum(result.Checksum, frame)
				result.Frames++
				result.ProcessedBytes += uint64(config.frameBytes)
			}
		}

		result.Ticks++
		if config.runtimeMode == "realtime" && time.Now().After(deadline.Add(config.ptime)) {
			result.DeadlineMisses++
		}
	}

	result.End = time.Now()
	if config.mode == "string" {
		result.RemainingBytes = uint64(len(accumulator))
		result.RemainingData = []byte(accumulator)
	} else {
		result.RemainingBytes = uint64(buffer.Len())
		result.RemainingData = buffer.Snapshot()
	}

	completed <- result
}

func expectedCallState(callID, ticks, frameBytes int, chunks []string) CallResult {
	pending := make([]byte, 0, frameBytes+maxChunkSize(chunks))
	result := CallResult{
		CallID:   callID,
		Checksum: combineChecksum(0, uint64(callID+1)),
	}

	for tick := 0; tick < ticks; tick++ {
		chunk := chunks[tick%len(chunks)]
		pending = append(pending, chunk...)
		result.InputBytes += uint64(len(chunk))

		for len(pending) >= frameBytes {
			result.Checksum = updateBytesChecksum(result.Checksum, pending[:frameBytes])
			result.Frames++
			result.ProcessedBytes += uint64(frameBytes)
			copy(pending, pending[frameBytes:])
			pending = pending[:len(pending)-frameBytes]
		}
		result.Ticks++
	}

	result.RemainingBytes = uint64(len(pending))
	result.RemainingData = append([]byte(nil), pending...)
	return result
}

func maxChunkSize(chunks []string) int {
	maximum := 0
	for _, chunk := range chunks {
		if len(chunk) > maximum {
			maximum = len(chunk)
		}
	}
	return maximum
}

func validateResults(results []CallResult, chunksByCall [][]string, ticks, frameBytes int) error {
	for callID, actual := range results {
		expected := expectedCallState(callID, ticks, frameBytes, chunksByCall[callID])
		if actual.Ticks != expected.Ticks {
			return fmt.Errorf("call %d: ticks mismatch", callID)
		}
		if actual.InputBytes != expected.InputBytes {
			return fmt.Errorf("call %d: input bytes mismatch", callID)
		}
		if actual.ProcessedBytes != expected.ProcessedBytes {
			return fmt.Errorf("call %d: processed bytes mismatch", callID)
		}
		if actual.Frames != expected.Frames {
			return fmt.Errorf("call %d: frame count mismatch", callID)
		}
		if actual.Checksum != expected.Checksum {
			return fmt.Errorf("call %d: checksum mismatch", callID)
		}
		if actual.RemainingBytes != expected.RemainingBytes {
			return fmt.Errorf("call %d: remaining byte count mismatch", callID)
		}
		if !equalBytes(actual.RemainingData, expected.RemainingData) {
			return fmt.Errorf("call %d: remaining data mismatch", callID)
		}
		if actual.InputBytes != actual.ProcessedBytes+actual.RemainingBytes {
			return fmt.Errorf("call %d: byte accounting mismatch", callID)
		}
	}
	return nil
}

func equalBytes(a, b []byte) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
}

func buildStateDigest(results []CallResult) uint64 {
	var digest uint64
	for callID, result := range results {
		digest = combineChecksum(digest, uint64(callID+1))
		digest = combineChecksum(digest, result.Ticks)
		digest = combineChecksum(digest, result.InputBytes)
		digest = combineChecksum(digest, result.ProcessedBytes)
		digest = combineChecksum(digest, result.Frames)
		digest = combineChecksum(digest, result.Checksum)
		digest = combineChecksum(digest, result.RemainingBytes)
		digest = combineChecksum(digest, uint64(crc32.ChecksumIEEE(result.RemainingData)))
	}
	return digest
}

func samplePeakHeap(stop <-chan struct{}, done chan<- uint64, initial uint64) {
	peak := initial
	ticker := time.NewTicker(10 * time.Millisecond)
	defer ticker.Stop()

	read := func() {
		var stats runtime.MemStats
		runtime.ReadMemStats(&stats)
		if stats.HeapAlloc > peak {
			peak = stats.HeapAlloc
		}
	}

	for {
		select {
		case <-ticker.C:
			read()
		case <-stop:
			read()
			done <- peak
			return
		}
	}
}

func fatalf(format string, args ...any) {
	fmt.Fprintf(os.Stderr, "ERROR: "+format+"\n", args...)
	os.Exit(1)
}

func main() {
	calls := flag.Int("calls", 50, "number of independent calls")
	mode := flag.String("mode", "string", "string or bytebuffer")
	runtimeMode := flag.String("runtime", "throughput", "throughput or realtime")
	frameBytes := flag.Int("frame", 1920, "PCM16 frame size in bytes")
	chunkOption := flag.String("chunk", "1024", "fixed chunk bytes or variable")
	ptimeMs := flag.Int("ptime", 20, "packet interval in milliseconds")
	durationSeconds := flag.Float64("duration", 10, "logical audio duration in seconds")
	flag.Parse()

	*mode = strings.ToLower(*mode)
	*runtimeMode = strings.ToLower(*runtimeMode)
	*chunkOption = strings.ToLower(*chunkOption)

	if *calls < 1 {
		fatalf("--calls must be a positive integer")
	}
	if *mode != "string" && *mode != "bytebuffer" {
		fatalf("--mode must be string or bytebuffer")
	}
	if *runtimeMode != "throughput" && *runtimeMode != "realtime" {
		fatalf("--runtime must be throughput or realtime")
	}
	if *frameBytes < 1 || *frameBytes%2 != 0 {
		fatalf("--frame must be a positive even integer for PCM16")
	}
	if *ptimeMs < 1 {
		fatalf("--ptime must be a positive integer")
	}
	if *durationSeconds <= 0 || math.IsNaN(*durationSeconds) || math.IsInf(*durationSeconds, 0) {
		fatalf("--duration must be greater than zero")
	}

	durationMs := int(math.Round(*durationSeconds * 1000))
	if durationMs < 1 {
		fatalf("--duration is too small")
	}

	var chunkSizes []int
	chunkMode := ""
	if *chunkOption == "variable" {
		chunkSizes = append([]int(nil), variableChunkSizes...)
		chunkMode = "variable"
	} else {
		var chunkBytes int
		if _, err := fmt.Sscanf(*chunkOption, "%d", &chunkBytes); err != nil || chunkBytes < 1 {
			fatalf("--chunk must be a positive integer or variable")
		}
		if fmt.Sprintf("%d", chunkBytes) != *chunkOption {
			fatalf("--chunk must be a positive integer or variable")
		}
		if chunkBytes%2 != 0 {
			fatalf("--chunk must be even for PCM16")
		}
		chunkSizes = []int{chunkBytes}
		chunkMode = fmt.Sprintf("fixed:%d", chunkBytes)
	}

	ticks := (durationMs + *ptimeMs - 1) / *ptimeMs
	chunksByCall := make([][]string, *calls)
	for callID := 0; callID < *calls; callID++ {
		chunksByCall[callID] = makePCMChunks(callID, chunkSizes)
	}

	config := workerConfig{
		mode:        *mode,
		runtimeMode: *runtimeMode,
		ticks:       ticks,
		ptime:       time.Duration(*ptimeMs) * time.Millisecond,
		frameBytes:  *frameBytes,
	}

	ready := make(chan struct{}, *calls)
	startGate := make(chan struct{})
	completed := make(chan CallResult, *calls)
	results := make([]CallResult, *calls)
	var benchmarkStart time.Time

	for callID := 0; callID < *calls; callID++ {
		go runCall(
			callID,
			config,
			chunksByCall[callID],
			&benchmarkStart,
			ready,
			startGate,
			completed,
		)
	}

	for i := 0; i < *calls; i++ {
		<-ready
	}

	var memoryInitial runtime.MemStats
	runtime.ReadMemStats(&memoryInitial)
	peakStop := make(chan struct{})
	peakDone := make(chan uint64, 1)
	go samplePeakHeap(peakStop, peakDone, memoryInitial.HeapAlloc)

	benchmarkStart = time.Now()
	close(startGate)

	latestEnd := benchmarkStart
	for i := 0; i < *calls; i++ {
		result := <-completed
		results[result.CallID] = result
		if result.End.After(latestEnd) {
			latestEnd = result.End
		}
	}

	close(peakStop)
	peakHeapAlloc := <-peakDone
	var memoryFinal runtime.MemStats
	runtime.ReadMemStats(&memoryFinal)
	if memoryFinal.HeapAlloc > peakHeapAlloc {
		peakHeapAlloc = memoryFinal.HeapAlloc
	}

	elapsed := latestEnd.Sub(benchmarkStart)
	var totalTicks uint64
	var totalInputBytes uint64
	var totalProcessedBytes uint64
	var totalFrames uint64
	var totalRemainingBytes uint64
	var totalDeadlineMisses uint64
	var totalDelay time.Duration
	var maxDelay time.Duration
	var globalChecksum uint64

	for callID, result := range results {
		totalTicks += result.Ticks
		totalInputBytes += result.InputBytes
		totalProcessedBytes += result.ProcessedBytes
		totalFrames += result.Frames
		totalRemainingBytes += result.RemainingBytes
		totalDeadlineMisses += result.DeadlineMisses
		totalDelay += result.DelayTotal
		if result.DelayMax > maxDelay {
			maxDelay = result.DelayMax
		}
		globalChecksum = combineChecksum(globalChecksum, uint64(callID+1))
		globalChecksum = combineChecksum(globalChecksum, result.Checksum)
	}

	// Validation is deliberately outside the measured interval and memory report.
	if err := validateResults(results, chunksByCall, ticks, *frameBytes); err != nil {
		fatalf("validation failed: %v", err)
	}
	stateDigest := buildStateDigest(results)

	elapsedSeconds := elapsed.Seconds()
	framesPerSecond := 0.0
	mibPerSecond := 0.0
	if elapsedSeconds > 0 {
		framesPerSecond = float64(totalFrames) / elapsedSeconds
		mibPerSecond = (float64(totalProcessedBytes) / 1048576) / elapsedSeconds
	}
	averageDelayMs := 0.0
	if totalTicks > 0 {
		averageDelayMs = float64(totalDelay) / float64(totalTicks) / float64(time.Millisecond)
	}

	fmt.Printf("language: Go\n")
	fmt.Printf("mode: %s\n", *mode)
	fmt.Printf("runtime_mode: %s\n", *runtimeMode)
	fmt.Printf("calls: %d\n", *calls)
	fmt.Printf("ptime_ms: %d\n", *ptimeMs)
	fmt.Printf("frame_bytes: %d\n", *frameBytes)
	fmt.Printf("chunk_mode: %s\n", chunkMode)
	fmt.Printf("chunk_sequence_bytes: %s\n", joinInts(chunkSizes))
	fmt.Printf("duration_configured_seconds: %.3f\n", float64(durationMs)/1000)
	fmt.Printf("elapsed_seconds: %.6f\n", elapsedSeconds)
	fmt.Printf("ticks_expected: %d\n", uint64(*calls)*uint64(ticks))
	fmt.Printf("ticks_processed: %d\n", totalTicks)
	fmt.Printf("input_bytes: %d\n", totalInputBytes)
	fmt.Printf("bytes_processed: %d\n", totalProcessedBytes)
	fmt.Printf("frames_processed: %d\n", totalFrames)
	fmt.Printf("frames_per_second: %.3f\n", framesPerSecond)
	fmt.Printf("mib_per_second: %.3f\n", mibPerSecond)
	fmt.Printf("checksum_global: %d\n", globalChecksum)
	fmt.Printf("call_state_digest: %d\n", stateDigest)
	fmt.Printf("bytes_remaining: %d\n", totalRemainingBytes)
	fmt.Printf("memory_initial_bytes: %d\n", memoryInitial.HeapAlloc)
	fmt.Printf("memory_peak_bytes: %d\n", peakHeapAlloc)
	fmt.Printf("memory_final_bytes: %d\n", memoryFinal.HeapAlloc)
	fmt.Printf("memory_initial_heap_alloc_bytes: %d\n", memoryInitial.HeapAlloc)
	fmt.Printf("memory_peak_heap_alloc_sampled_bytes: %d\n", peakHeapAlloc)
	fmt.Printf("memory_final_heap_alloc_bytes: %d\n", memoryFinal.HeapAlloc)
	fmt.Printf("memory_initial_heap_sys_bytes: %d\n", memoryInitial.HeapSys)
	fmt.Printf("memory_final_heap_sys_bytes: %d\n", memoryFinal.HeapSys)
	fmt.Printf("memory_total_alloc_delta_bytes: %d\n", memoryFinal.TotalAlloc-memoryInitial.TotalAlloc)

	if *runtimeMode == "realtime" {
		fmt.Printf("deadline_misses: %d\n", totalDeadlineMisses)
		fmt.Printf("max_delay_ms: %.3f\n", float64(maxDelay)/float64(time.Millisecond))
		fmt.Printf("average_delay_ms: %.3f\n", averageDelayMs)
	} else {
		fmt.Printf("deadline_misses: n/a\n")
		fmt.Printf("max_delay_ms: n/a\n")
		fmt.Printf("average_delay_ms: n/a\n")
	}

	fmt.Printf("validation: ok\n")
}

func joinInts(values []int) string {
	parts := make([]string, len(values))
	for i, value := range values {
		parts[i] = fmt.Sprintf("%d", value)
	}
	return strings.Join(parts, ",")
}
