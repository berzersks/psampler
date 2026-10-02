package main

import (
	"bytes"
	"encoding/binary"
	"testing"
)

func TestFixtureAndDownmixGolden(t *testing.T) {
	c := config{source: 44100, channels: 2}
	var bank [][]byte
	var all []byte
	for i := 0; i < fixtureSlots; i++ {
		b := fixture(44100, 2, 882, i)
		bank = append(bank, b)
		all = append(all, b...)
	}
	if hash(all) != "0eb4aa0d2359c6fa429325157b94a2c217158d5c843d90f2843aa9e23800e427" {
		t.Fatal("fixture changed")
	}
	if verifyDownmix(bank, c) != "52546c6655bda6e365d262681b2482971219336ed664c7212d7a0a1aebfa1e64" {
		t.Fatal("downmix changed")
	}
	// Explicit signed odd sums and extremes; protects division toward zero.
	pairs := [][2]int16{{-32768, -32768}, {32767, 32767}, {-32768, 32767}, {-3, 0}, {3, 0}, {-32768, 0}, {32767, 0}}
	want := []int16{-32768, 32767, 0, -1, 1, -16384, 16383}
	var p PCMBuffer
	p.Reset(8000, 2)
	for _, pair := range pairs {
		var b [4]byte
		binary.LittleEndian.PutUint16(b[:], uint16(pair[0]))
		binary.LittleEndian.PutUint16(b[2:], uint16(pair[1]))
		p.Append(b[:])
	}
	p.ToMono()
	for i, s := range want {
		if int16(binary.LittleEndian.Uint16(p.data[i*2:])) != s {
			t.Fatalf("sample %d", i)
		}
	}
}
func TestScenariosAndReuse(t *testing.T) {
	for _, c := range []config{
		{source: 8000, channels: 1, target: 8000, targetChannels: 1},
		{source: 8000, channels: 2, target: 8000, targetChannels: 1},
		{source: 44100, channels: 1, target: 8000, targetChannels: 1},
		{source: 44100, channels: 2, target: 8000, targetChannels: 1},
	} {
		b := fixture(c.source, c.channels, c.source/50, 0)
		var p PCMBuffer
		pipeline(&p, b, c)
		validate(&p, b, c)
		want := append([]byte(nil), p.data...)
		capacity := p.Capacity()
		p.Reset(c.source, c.channels)
		if p.Size() != 0 || p.Capacity() != capacity || p.SampleRate != c.source || p.Channels != c.channels {
			t.Fatal("reset")
		}
		for i := 0; i < 100; i++ {
			pipeline(&p, b, c)
			if !bytes.Equal(want, p.data) {
				t.Fatal("reused output")
			}
		}
		allocs := testing.AllocsPerRun(100, func() { pipeline(&p, b, c) })
		if allocs != 0 {
			t.Fatalf("hot pipeline allocated: %g", allocs)
		}
		expectedBytes := 320
		if c.source != c.target {
			expectedBytes = 308
		}
		if p.Size() != expectedBytes {
			t.Fatalf("output bytes: %d", p.Size())
		}
	}
}
func TestStreamingBoundaries(t *testing.T) {
	for _, n := range []int{1, 32, 33, 882, 8192, 9000, 20000} {
		c := config{source: 44100, channels: 1, target: 8000, targetChannels: 1}
		b := make([]byte, n*2)
		var p PCMBuffer
		pipeline(&p, b, c)
		validate(&p, b, c)
		if !bytes.Equal(p.data, make([]byte, p.Size())) {
			t.Fatal("silence corrupted")
		}
	}
}
func TestStereoResample(t *testing.T) {
	c := config{source: 44100, channels: 2, target: 8000, targetChannels: 2}
	b := fixture(c.source, c.channels, 882, 0)
	var p PCMBuffer
	pipeline(&p, b, c)
	validate(&p, b, c)
	if p.Size() != 616 {
		t.Fatal("stereo size")
	}
	// Independent mono runs must reproduce each interleaved channel. This
	// catches accidental sharing of phase/DC state between stereo channels.
	for channel := 0; channel < 2; channel++ {
		input := make([]byte, len(b)/2)
		for i := 0; i < len(b)/4; i++ {
			copy(input[i*2:i*2+2], b[i*4+channel*2:i*4+channel*2+2])
		}
		var mono PCMBuffer
		mono.Reset(44100, 1)
		mono.Append(input)
		mono.Resample(8000)
		for i := 0; i < mono.Size()/2; i++ {
			if !bytes.Equal(mono.data[i*2:i*2+2], p.data[i*4+channel*2:i*4+channel*2+2]) {
				t.Fatal("stereo DSP state or interleaving corrupted")
			}
		}
	}
}

func TestResampledSignal(t *testing.T) {
	c := config{source: 44100, channels: 2, target: 8000, targetChannels: 1}
	var p PCMBuffer
	pipeline(&p, fixture(44100, 2, 882, 0), c)
	positive, negative := false, false
	var energy int64
	for i := 0; i < p.Size(); i += 2 {
		s := int64(int16(binary.LittleEndian.Uint16(p.data[i:])))
		positive = positive || s > 0
		negative = negative || s < 0
		energy += s * s
	}
	if !positive || !negative || energy/int64(p.Size()/2) < 100000 {
		t.Fatal("varied tone lost or signal corrupted")
	}
	for phase := 0; phase < phases; phase++ {
		sum := 0.0
		for _, h := range p.filter[phase*taps : (phase+1)*taps] {
			sum += h
		}
		if sum < 0.999999999 || sum > 1.000000001 {
			t.Fatal("FIR phase lost unity gain")
		}
	}
}
