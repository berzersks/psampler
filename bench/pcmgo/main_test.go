package main

import (
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
