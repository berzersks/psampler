// Standalone Linux benchmark: build with go build -o pcm_benchmark_go pcm_benchmark.go.
package main

import (
	"crypto/sha256"
	"encoding/binary"
	"flag"
	"fmt"
	"math"
	"os"
	"runtime"
	"syscall"
	"time"
)

const fixtureSlots = 8
const taps = 64
const phases = 256

// PCM16LE contiguous storage. Both backing arrays and DSP scratch are reused.
// Size and Capacity are bytes; signal state is fresh for each complete buffer.
type PCMBuffer struct {
	data, work                 []byte
	SampleRate, Channels       int
	filter                     []float64
	filterSource, filterTarget int
	scratch                    [8192]int16
}

func (p *PCMBuffer) Size() int     { return len(p.data) }
func (p *PCMBuffer) Capacity() int { return cap(p.data) }
func (p *PCMBuffer) Clear()        { p.data = p.data[:0] }
func (p *PCMBuffer) Reset(rate, channels int) {
	if rate < 1 || uint64(rate) > math.MaxUint32 || (channels != 1 && channels != 2) {
		panic("invalid PCM metadata")
	}
	p.Clear()
	p.SampleRate, p.Channels = rate, channels
}
func (p *PCMBuffer) Append(b []byte) {
	if len(b)%(2*p.Channels) != 0 {
		panic("incomplete PCM16LE frame")
	}
	p.data = append(p.data, b...)
}
func (p *PCMBuffer) ToMono() {
	if p.Channels == 1 {
		return
	}
	for i, j := 0, 0; i < len(p.data); i, j = i+4, j+2 {
		l := int32(int16(binary.LittleEndian.Uint16(p.data[i:])))
		r := int32(int16(binary.LittleEndian.Uint16(p.data[i+2:])))
		binary.LittleEndian.PutUint16(p.data[j:], uint16(int16((l+r)/2)))
	}
	p.data = p.data[:len(p.data)/2]
	p.Channels = 1
}
func bessel(x float64) float64 {
	sum, term := 1.0, 1.0
	for i := 1; i < 50; i++ {
		term *= x / 2 / float64(i)
		sum += term * term
	}
	return sum
}
func (p *PCMBuffer) prepareFilter(target int) {
	if p.filterSource == p.SampleRate && p.filterTarget == target {
		return
	}
	if p.filter == nil {
		p.filter = make([]float64, taps*phases)
	}
	cutoff := math.Min(float64(target)/float64(p.SampleRate), 1) * 0.95
	var window [taps]float64
	for i := range window {
		x := (float64(i) - 31.5) / 31.5
		window[i] = bessel(8.6*math.Sqrt(1-x*x)) / bessel(8.6)
	}
	for phase := 0; phase < phases; phase++ {
		sum := 0.0
		for i := 0; i < taps; i++ {
			x := 2 * cutoff * (float64(i) - 31.5 + float64(phase)/phases)
			sinc := 1.0
			if math.Abs(x) >= 1e-8 {
				sinc = math.Sin(math.Pi*x) / (math.Pi * x)
			}
			h := sinc * 2 * cutoff * window[i]
			p.filter[phase*taps+i] = h
			sum += h
		}
		if sum > 0 {
			for i := 0; i < taps; i++ {
				p.filter[phase*taps+i] /= sum
			}
		}
	}
	p.filterSource, p.filterTarget = p.SampleRate, target
}

// Isolated Go polyphase sinc/Kaiser FIR matching the psampler DSP parameters.
// No padding or tail flush; 8192-sample streaming chunks and fresh phase/DC.
// Coefficients are cached, rather than regenerated inside each frame.
func (p *PCMBuffer) Resample(target int) {
	if target < 1 || uint64(target) > math.MaxUint32 {
		panic("invalid target rate")
	}
	if p.SampleRate == target {
		return
	}
	if len(p.data) == 0 {
		p.SampleRate = target
		return
	}
	p.prepareFilter(target)
	ratio := float64(target) / float64(p.SampleRate)
	step := 1 / ratio
	stride := 2 * p.Channels
	frames := len(p.data) / stride
	capacity := (int(math.Ceil(float64(frames)*ratio)) + 1) * stride
	if cap(p.work) < capacity {
		p.work = make([]byte, capacity)
	} else {
		p.work = p.work[:capacity]
	}
	outputFrames := 0
	for channel := 0; channel < p.Channels; channel++ {
		used, offset, produced := 0, 0, 0
		pos, dc := 0.0, 0.0
		for offset < frames {
			count := min(frames-offset, len(p.scratch)-used)
			for i := 0; i < count; i++ {
				p.scratch[used+i] = int16(binary.LittleEndian.Uint16(p.data[(offset+i)*stride+channel*2:]))
			}
			used += count
			offset += count
			bound := 0
			if used > taps/2 {
				bound = int(float64(used-taps/2) * ratio)
			}
			blockProduced := 0
			for blockProduced < bound && pos < float64(used-taps/2) {
				base := int(pos)
				phase := min(int((pos-float64(base))*phases), phases-1)
				filter := p.filter[phase*taps : (phase+1)*taps]
				sample := 0.0
				for i, h := range filter {
					index := base - taps/2 + i
					if index >= 0 && index < used {
						sample += float64(p.scratch[index]) * h
					}
				}
				dc = 0.9995*dc + 0.0005*sample
				sample -= dc
				sample = math.Max(-32768, math.Min(32767, sample))
				binary.LittleEndian.PutUint16(p.work[produced*stride+channel*2:], uint16(int16(math.RoundToEven(sample))))
				produced++
				blockProduced++
				pos += step
			}
			if pos >= float64(used) {
				used = 0
				pos = 0
			} else {
				consumed := int(pos)
				copy(p.scratch[:], p.scratch[consumed:used])
				used -= consumed
				pos -= float64(consumed)
			}
			if count == 0 && blockProduced == 0 {
				panic("rate ratio cannot advance streaming buffer")
			}
		}
		if channel == 0 {
			outputFrames = produced
		} else if outputFrames != produced {
			panic("channel length mismatch")
		}
	}
	p.data, p.work = p.work[:outputFrames*stride], p.data[:0]
	p.SampleRate = target
}

// Integer triangle tones avoid PHP/Go libm differences in the input fixture.
func fixture(rate, channels, n, slot int) []byte {
	b := make([]byte, n*channels*2)
	tone := func(index, hz int) int {
		phase := (index * hz * 4096 / rate) % 4096
		if phase < 2048 {
			return phase - 1024
		}
		return 3072 - phase
	}
	for j := 0; j < n; j++ {
		index := slot*n + j
		gain := 6 + (slot%3)*5 + (j*3/n)*3
		l := (tone(index, 440)*3 + tone(index, 997)) * gain / 4
		r := (tone(index+17, 659)*3 - tone(index, 123)) * gain / 4
		if slot == 7 || (j >= n/3 && j < n/2) {
			l = 0
			r = 0
		}
		binary.LittleEndian.PutUint16(b[j*channels*2:], uint16(int16(l)))
		if channels == 2 {
			binary.LittleEndian.PutUint16(b[j*4+2:], uint16(int16(r)))
		}
	}
	return b
}

type config struct {
	calls, frames, ptime, source, channels, target, targetChannels int
	mode                                                           string
}

func pipeline(p *PCMBuffer, b []byte, c config) {
	p.Reset(c.source, c.channels)
	p.Append(b)
	if c.channels == 2 && c.targetChannels == 1 {
		p.ToMono()
	}
	p.Resample(c.target)
}

type callState struct {
	pcm                   PCMBuffer
	frames, bytes, misses int64
	delay, maxDelay       time.Duration
}

func usageCPU() (float64, float64) {
	var r syscall.Rusage
	if err := syscall.Getrusage(syscall.RUSAGE_SELF, &r); err != nil {
		panic(err)
	}
	return float64(r.Utime.Sec) + float64(r.Utime.Usec)/1e6, float64(r.Stime.Sec) + float64(r.Stime.Usec)/1e6
}
func must(ok bool, message string) {
	if !ok {
		panic(message)
	}
}
func hash(b []byte) string { return fmt.Sprintf("%x", sha256.Sum256(b)) }
func validate(p *PCMBuffer, b []byte, c config) {
	must(p.SampleRate == c.target && p.Channels == c.targetChannels, "invalid output metadata")
	must(p.Size()%(2*c.targetChannels) == 0 && p.Capacity() >= p.Size(), "invalid PCM size/capacity")
	n := len(b) / (c.channels * 2)
	out := p.Size() / (c.targetChannels * 2)
	tolerance := float64(taps)/float64(c.source) + 2/float64(c.target)
	must(math.Abs(float64(out)/float64(c.target)-float64(n)/float64(c.source)) <= tolerance, "output duration mismatch")
	if c.source == c.target || float64(max(n-32, 0))*float64(c.target)/float64(c.source) >= 1 {
		must(out > 0, "unexpected empty output")
	}
	var reference PCMBuffer
	pipeline(&reference, b, c)
	must(hash(reference.data) == hash(p.data), "nondeterministic output")
}
func verifyDownmix(bank [][]byte, c config) string {
	h := sha256.New()
	edges := []int16{-32768, -32768, 32767, 32767, -32768, 32767, -3, 0, 3, 0, -32768, 0, 32767, 0}
	b := make([]byte, len(edges)*2)
	for i, s := range edges {
		binary.LittleEndian.PutUint16(b[i*2:], uint16(s))
	}
	inputs := [][]byte{b}
	if c.channels == 2 {
		inputs = append(inputs, bank...)
	}
	for _, input := range inputs {
		var p PCMBuffer
		p.Reset(c.source, 2)
		p.Append(input)
		p.ToMono()
		for i := 0; i < len(input)/4; i++ {
			l := int32(int16(binary.LittleEndian.Uint16(input[i*4:])))
			r := int32(int16(binary.LittleEndian.Uint16(input[i*4+2:])))
			must(int16(binary.LittleEndian.Uint16(p.data[i*2:])) == int16((l+r)/2), "downmix parity failure")
		}
		h.Write(p.data)
	}
	return fmt.Sprintf("%x", h.Sum(nil))
}
func main() {
	defer func() {
		if e := recover(); e != nil {
			fmt.Fprintln(os.Stderr, "ERROR:", e)
			os.Exit(1)
		}
	}()
	c := config{}
	flag.IntVar(&c.calls, "calls", 50, "independent calls")
	flag.IntVar(&c.frames, "frames", 1000, "frames per call (takes precedence over duration)")
	flag.IntVar(&c.ptime, "ptime", 20, "frame duration in integer ms")
	flag.IntVar(&c.source, "source-rate", 44100, "source sample rate")
	flag.IntVar(&c.channels, "source-channels", 2, "source channels")
	flag.IntVar(&c.target, "target-rate", 8000, "target sample rate")
	flag.IntVar(&c.targetChannels, "target-channels", 1, "target channels")
	flag.StringVar(&c.mode, "runtime", "throughput", "throughput or realtime")
	duration := flag.Float64("duration", 0, "logical seconds per call; used only without --frames")
	verify := flag.Bool("verify", false, "validate all fixture slots after measurement")
	flag.Parse()
	must(flag.NArg() == 0, "unexpected positional arguments")
	hasFrames, hasDuration := false, false
	flag.Visit(func(f *flag.Flag) {
		if f.Name == "frames" {
			hasFrames = true
		}
		if f.Name == "duration" {
			hasDuration = true
		}
	})
	must(c.ptime > 0 && c.ptime <= math.MaxInt32, "invalid ptime")
	if hasDuration {
		must(*duration > 0 && !math.IsInf(*duration, 0) && !math.IsNaN(*duration), "invalid duration")
		if !hasFrames {
			f := math.Ceil(*duration * 1000 / float64(c.ptime))
			must(f < float64(math.MaxInt64), "duration overflow")
			c.frames = int(f)
		}
	}
	must(c.calls > 0 && c.frames > 0 && c.calls <= math.MaxInt64/c.frames, "invalid calls/frames")
	must(c.source > 0 && uint64(c.source) <= math.MaxUint32 && c.target > 0 && uint64(c.target) <= math.MaxUint32, "invalid rates")
	must((c.channels == 1 || c.channels == 2) && (c.targetChannels == 1 || c.targetChannels == 2) && c.targetChannels <= c.channels, "only mono/stereo and downmix supported")
	must(c.mode == "throughput" || c.mode == "realtime", "invalid runtime")
	samples64 := int64(c.source) * int64(c.ptime)
	must(samples64%1000 == 0, "ptime must describe an integer number of source samples")
	samples := int(samples64 / 1000)
	must(samples > 0 && int64(c.calls)*int64(c.frames) <= math.MaxInt64/int64(samples*c.channels*2), "input byte count overflow")
	bank := make([][]byte, fixtureSlots)
	fixtureHash := sha256.New()
	for i := range bank {
		bank[i] = fixture(c.source, c.channels, samples, i)
		fixtureHash.Write(bank[i])
	}
	states := make([]callState, c.calls)
	ready := make(chan struct{}, c.calls)
	gate := make(chan struct{})
	done := make(chan struct{}, c.calls)
	var start time.Time
	for i := range states {
		go func(s *callState) {
			for _, b := range bank {
				pipeline(&s.pcm, b, c)
			} // warmup/capacity/filter before barrier
			timer := time.NewTimer(time.Hour)
			if !timer.Stop() {
				<-timer.C
			}
			defer timer.Stop()
			ready <- struct{}{}
			<-gate
			tick := time.Duration(c.ptime) * time.Millisecond
			for frame := 0; frame < c.frames; frame++ {
				var due time.Time
				if c.mode == "realtime" {
					due = start.Add(time.Duration(frame) * tick)
					if wait := time.Until(due); wait > 0 {
						timer.Reset(wait)
						<-timer.C
					}
				}
				pipeline(&s.pcm, bank[frame%fixtureSlots], c)
				s.frames++
				s.bytes += int64(s.pcm.Size())
				if c.mode == "realtime" {
					late := time.Since(due)
					s.delay += late
					if late > s.maxDelay {
						s.maxDelay = late
					}
					if late > tick {
						s.misses++
					}
				}
			}
			done <- struct{}{}
		}(&states[i])
	}
	for range states {
		<-ready
	}
	var initial, final runtime.MemStats
	runtime.ReadMemStats(&initial)
	u0, s0 := usageCPU()
	start = time.Now()
	close(gate)
	for range states {
		<-done
	}
	elapsed := time.Since(start).Seconds()
	u1, s1 := usageCPU()
	runtime.ReadMemStats(&final)
	var frames, bytes, misses int64
	var delay, maxDelay time.Duration
	for i := range states {
		s := &states[i]
		frames += s.frames
		bytes += s.bytes
		misses += s.misses
		delay += s.delay
		maxDelay = max(maxDelay, s.maxDelay)
		must(s.frames == int64(c.frames), "frame count mismatch")
		must(s.pcm.SampleRate == c.target && s.pcm.Channels == c.targetChannels, "final metadata mismatch")
	}
	hashes := ""
	for i := 0; i < min(c.calls, 3); i++ {
		validate(&states[i].pcm, bank[(c.frames-1)%fixtureSlots], c)
		if i > 0 {
			hashes += ","
		}
		hashes += hash(states[i].pcm.data)
	}
	downmixHash := verifyDownmix(bank, c)
	var expected PCMBuffer
	var cycleBytes, tailBytes int64
	for i, b := range bank {
		pipeline(&expected, b, c)
		cycleBytes += int64(expected.Size())
		if i < c.frames%fixtureSlots {
			tailBytes += int64(expected.Size())
		}
		if *verify {
			validate(&expected, b, c)
			for repeat := 0; repeat < 3; repeat++ {
				pipeline(&expected, b, c)
				validate(&expected, b, c)
			}
		}
	}
	expectedBytes := int64(c.calls) * (int64(c.frames/fixtureSlots)*cycleBytes + tailBytes)
	must(bytes == expectedBytes && frames == int64(c.calls)*int64(c.frames), "counter mismatch")
	audio := float64(frames) * float64(samples) / float64(c.source)
	cpu := u1 - u0 + s1 - s0
	fmt.Printf("language: Go\nimplementation: native PCMBuffer / cached sinc-Kaiser FIR\nruntime_mode: %s\ncalls: %d\nframes_per_call: %d\ntotal_frames: %d\nptime_ms: %d\n", c.mode, c.calls, c.frames, c.calls*c.frames, c.ptime)
	fmt.Printf("gomaxprocs: %d\n", runtime.GOMAXPROCS(0))
	fmt.Printf("source_rate: %d\nsource_channels: %d\nsource_frame_bytes: %d\ntarget_rate: %d\ntarget_channels: %d\n", c.source, c.channels, len(bank[0]), c.target, c.targetChannels)
	fmt.Printf("elapsed_seconds: %.6f\nframes_processed: %d\nframes_per_second: %.3f\ninput_bytes: %d\noutput_bytes: %d\naudio_seconds_processed: %.6f\naudio_seconds_per_wall_second: %.3f\n", elapsed, frames, float64(frames)/elapsed, frames*int64(len(bank[0])), bytes, audio, audio/elapsed)
	fmt.Printf("cpu_user_seconds: %.6f\ncpu_system_seconds: %.6f\ncpu_total_seconds: %.6f\naverage_cpu_percent: %.3f\n", u1-u0, s1-s0, cpu, cpu/elapsed*100)
	fmt.Printf("memory_initial_bytes: %d\nmemory_final_bytes: %d\n", initial.HeapAlloc, final.HeapAlloc)
	fmt.Printf("HeapAlloc_initial: %d\nHeapAlloc_final: %d\nHeapSys_initial: %d\nHeapSys_final: %d\nTotalAlloc_initial: %d\nTotalAlloc_final: %d\nMallocs_initial: %d\nMallocs_final: %d\nFrees_initial: %d\nFrees_final: %d\nNumGC_initial: %d\nNumGC_final: %d\n", initial.HeapAlloc, final.HeapAlloc, initial.HeapSys, final.HeapSys, initial.TotalAlloc, final.TotalAlloc, initial.Mallocs, final.Mallocs, initial.Frees, final.Frees, initial.NumGC, final.NumGC)
	if c.mode == "realtime" {
		fmt.Printf("deadline_misses: %d\nmax_delay_ms: %.3f\naverage_delay_ms: %.3f\n", misses, float64(maxDelay)/1e6, float64(delay)/float64(frames)/1e6)
	} else {
		fmt.Println("deadline_misses: n/a\nmax_delay_ms: n/a\naverage_delay_ms: n/a")
	}
	fmt.Printf("fixture_sha256: %x\ndownmix_sha256: %s\noutput_sha256: %s\nverify: %t\nvalidation: ok\n", fixtureHash.Sum(nil), downmixHash, hashes, *verify)
}
