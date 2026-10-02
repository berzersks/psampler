package main

import (
	"fmt"
	"testing"
	"time"
)

func TestRunCallModes(t *testing.T) {
	chunkCases := [][]int{{2}, {160}, {1024}, {1920}, {8192}, variableChunkSizes}
	for _, frameBytes := range []int{2, 14, 320, 1920, 4096} {
		for _, sizes := range chunkCases {
			for _, mode := range []string{"string", "bytebuffer", "best"} {
				t.Run(fmt.Sprintf("%s/frame=%d/chunks=%s", mode, frameBytes, joinInts(sizes)), func(t *testing.T) {
					const calls, ticks = 3, 37
					chunks := make([][]string, calls)
					ready := make(chan struct{}, calls)
					gate := make(chan struct{})
					completed := make(chan CallResult, calls)
					config := workerConfig{mode: mode, runtimeMode: "throughput", ticks: ticks, frameBytes: frameBytes}
					var start time.Time
					for callID := range chunks {
						chunks[callID] = makePCMChunks(callID, sizes)
						go runCall(callID, config, chunks[callID], &start, ready, gate, completed)
					}
					for range chunks {
						<-ready
					}
					start = time.Now()
					close(gate)
					results := make([]CallResult, calls)
					for range chunks {
						result := <-completed
						results[result.CallID] = result
					}
					if err := validateResults(results, chunks, ticks, frameBytes); err != nil {
						t.Fatal(err)
					}
				})
			}
		}
	}
}

func TestFrameBuffer(t *testing.T) {
	// Empty and single-byte chunks exercise incomplete PCM16 samples as well
	// as frames spanning more than two chunks. Consume must keep inputs intact.
	chunks := [][]byte{nil, {1}, {}, {2}, {3, 4, 5, 6, 7, 8, 9}, {10}, {11}}
	b := frameBuffer{frame: make([]byte, 4)}
	var checksum, frames uint64
	var input []byte
	for _, chunk := range chunks {
		before := append([]byte(nil), chunk...)
		input = append(input, chunk...)
		var n uint64
		checksum, n = b.Consume(chunk, checksum)
		frames += n
		if !equalBytes(chunk, before) {
			t.Fatal("incoming chunk was modified")
		}
		var expectedChecksum uint64
		for pos := 0; pos+4 <= len(input); pos += 4 {
			expectedChecksum = updateBytesChecksum(expectedChecksum, input[pos:pos+4])
		}
		if frames != uint64(len(input)/4) || checksum != expectedChecksum {
			t.Fatalf("after %d bytes: frames=%d checksum=%d", len(input), frames, checksum)
		}
		if !equalBytes(b.frame[:b.pending], input[len(input)/4*4:]) {
			t.Fatalf("after %d bytes: incorrect remainder", len(input))
		}
	}
}

func TestFrameBufferNoAllocations(t *testing.T) {
	b := frameBuffer{frame: make([]byte, 1920)}
	pcm := make([]byte, 8192)
	allocations := testing.AllocsPerRun(100, func() {
		b.pending = 0
		checksum, _ := b.Consume(pcm[:1024], 0)
		_, _ = b.Consume(pcm, checksum)
	})
	if allocations != 0 {
		t.Fatalf("Consume allocated %g times; want zero", allocations)
	}
}
