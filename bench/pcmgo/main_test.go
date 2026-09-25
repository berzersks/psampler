package main

import (
	"encoding/binary"
	"math"
	"math/rand"
	"os"
	"testing"
)

func TestInputBounds(t *testing.T) {
	analyzer := NewAnalyzer()
	if r, err := analyzer.Analyze(nil); err != nil || r.Signal != "silence" {
		t.Fatalf("empty: %+v %v", r, err)
	}
	if _, err := analyzer.Analyze([]byte{0}); err == nil {
		t.Fatal("odd PCM accepted")
	}
	if _, err := analyzer.Analyze(make([]byte, 240002)); err == nil {
		t.Fatal("oversized PCM accepted")
	}
}

func TestRingEvidenceResets(t *testing.T) {
	analyzer := NewAnalyzer()
	pcm := make([]byte, 160000)
	for _, start := range []int{0, 40000} {
		for i := 0; i < 8000; i++ {
			sample := int16(math.Round(12000 * math.Sin(2*math.Pi*425*float64(i)/8000)))
			binary.LittleEndian.PutUint16(pcm[(start+i)*2:], uint16(sample))
		}
	}
	ring, err := analyzer.Analyze(pcm)
	if err != nil || ring.Ring.PulseCount != 2 || ring.Ring.MatchedCount != 2 || ring.Ring.DisturbanceAt != nil {
		t.Fatalf("two pulses: %+v %v", ring.Ring, err)
	}
	if analyzer.RingPCM != nil {
		t.Fatal("analyzer retained PCM after analysis")
	}
	empty, err := analyzer.Analyze(nil)
	if err != nil || empty.Ring.PulseCount != 0 || empty.Ring.Reason != "pcm_vazio" {
		t.Fatalf("state leaked: %+v %v", empty.Ring, err)
	}
}

func TestRandomPCMWindowBounds(t *testing.T) {
	rng := rand.New(rand.NewSource(4169))
	analyzers := [2]*Analyzer{NewAnalyzer(), NewAnalyzer()}
	for i := 0; i < 1024; i++ {
		n := rng.Intn(8001) * 2
		if i%128 == 0 {
			n = 240000
		}
		pcm := make([]byte, n)
		if _, err := rng.Read(pcm); err != nil {
			t.Fatal(err)
		}
		a := analyzers[i%len(analyzers)]
		result, _ := a.Analyze(pcm) // Random noise can exceed the segment limit.
		if a.RingPCM != nil || result.Ring.PulseCount > 30 {
			t.Fatalf("retained PCM or invalid pulse count at case %d", i)
		}
		if i%256 == 0 {
			temporary := NewAnalyzer()
			temporary.Analyze(pcm)
			if temporary.RingPCM != nil {
				t.Fatal("temporary analyzer retained PCM")
			}
		}
	}
}

func BenchmarkAnalyze(b *testing.B) {
	pcm, err := os.ReadFile("../fixtures/15s_voice_synthetic.pcm")
	if err != nil {
		b.Fatal(err)
	}
	analyzer := NewAnalyzer()
	for i := 0; i < 25; i++ {
		analyzer.Analyze(pcm)
	}
	b.ReportAllocs()
	b.ResetTimer()
	var checksum uint64
	for i := 0; i < b.N; i++ {
		result, err := analyzer.Analyze(pcm)
		if err != nil {
			b.Fatal(err)
		}
		checksum += digest(result)
	}
	sink = checksum
}
