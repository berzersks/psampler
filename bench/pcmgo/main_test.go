package main

import (
	"encoding/binary"
	"math"
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
	empty, err := analyzer.Analyze(nil)
	if err != nil || empty.Ring.PulseCount != 0 || empty.Ring.Reason != "pcm_vazio" {
		t.Fatalf("state leaked: %+v %v", empty.Ring, err)
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
