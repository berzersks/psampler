package main

import (
	"encoding/binary"
	"encoding/json"
	"flag"
	"fmt"
	"math"
	"os"
	"path/filepath"
	"runtime"
	"runtime/pprof"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

const maxFrames = 750
const maxSegments = 64

var frequencies = [8]float64{125, 250, 425, 700, 1000, 1500, 2200, 3000}

type Frame struct {
	RMS, CV, Diff float64
	Cross         int
	Active        bool
}
type RingPulse struct {
	Start      int `json:"start_ms"`
	End        int `json:"end_ms"`
	Duration   int `json:"duration_ms"`
	ToneFrames int `json:"tone_frames"`
}
type RingFrame struct {
	Index      int     `json:"index"`
	Start      int     `json:"start_ms"`
	End        int     `json:"end_ms"`
	State      string  `json:"state"`
	RMS        float64 `json:"rms_dbfs"`
	ACRMS      float64 `json:"ac_rms_dbfs"`
	Frequency  float64 `json:"ring_frequency_hz"`
	Level      float64 `json:"ring_level_dbfs"`
	Prominence float64 `json:"prominence_db"`
	Purity     float64 `json:"tone_purity_db"`
}
type RingResult struct {
	Duration            int         `json:"duration_ms"`
	PulseCount          int         `json:"pulse_count"`
	MatchedCount        int         `json:"matched_pulse_count"`
	HasPattern          bool        `json:"has_ring_pattern"`
	HasCadence          bool        `json:"has_valid_cadence"`
	FromStartToEnd      bool        `json:"ring_from_start_to_end"`
	DisturbanceAt       *int        `json:"disturbance_at_ms"`
	DisturbanceDuration int         `json:"disturbance_duration_ms"`
	Confidence          float64     `json:"confidence"`
	Reason              string      `json:"reason"`
	Pulses              []RingPulse `json:"pulses"`
	Matched             []RingPulse `json:"matched_pulses"`
	Periods             []int       `json:"periods_ms"`
	Frames              []RingFrame `json:"frames"`
}
type Features struct {
	Signal       string  `json:"signal"`
	ActiveFrames int     `json:"active_frames"`
	FirstActive  int     `json:"first_active_ms"`
	RMSMean      float64 `json:"rms_mean_dbfs"`
	RMSStd       float64 `json:"rms_std_db"`
	CrossMean    float64 `json:"crossing_mean"`
	CrossStd     float64 `json:"crossing_std"`
	CV           float64 `json:"crossing_interval_cv"`
	Difference   float64 `json:"normalized_difference"`
	Dominance    float64 `json:"dominant_tone_strength"`
	Variability  float64 `json:"spectral_variability"`
	Entropy      float64 `json:"spectral_entropy"`
}
type Segment struct {
	Start    int      `json:"started_at_ms"`
	End      int      `json:"ended_at_ms"`
	Duration int      `json:"duration_ms"`
	Signal   string   `json:"signal"`
	Voice    int      `json:"vad_voice_ms,omitempty"`
	Longest  int      `json:"vad_longest_ms,omitempty"`
	Features Features `json:"features"`
}
type Result struct {
	Duration          int        `json:"duration_ms"`
	Active            int        `json:"active_audio_ms"`
	Silence           int        `json:"silence_ms"`
	Voice             int        `json:"voice_ms"`
	Longest           int        `json:"longest_segment_ms"`
	SegmentCount      int        `json:"segment_count"`
	FirstVoiceSegment int        `json:"first_voice_segment_ms"`
	PauseCount        int        `json:"pause_count"`
	MeanPause         float64    `json:"mean_pause_ms"`
	FirstVoice        int        `json:"started_at_ms"`
	LastVoice         int        `json:"last_voice_ms"`
	Tone              int        `json:"tone_ms"`
	Noise             int        `json:"noise_ms"`
	Music             int        `json:"music_ms"`
	VoiceRatio        float64    `json:"voice_ratio"`
	SilenceRatio      float64    `json:"silence_ratio"`
	Signal            string     `json:"signal"`
	SignalFeatures    Features   `json:"signal_features"`
	Segments          []Segment  `json:"segments"`
	Ring              RingResult `json:"ring"`
}
type Analyzer struct {
	Coeff                          [8]float64
	Frames                         [maxFrames]Frame
	Segments                       [maxSegments]Segment
	RingCoeff                      [24]float64
	RingHann                       [4000]float64
	RingX, RingY                   [24]float64
	RingSquares                    float64
	RingSum                        float64
	RingIndex                      int
	RingFrames                     [30]RingFrame
	RingPulses                     [30]RingPulse
	RingMatched                    [30]RingPulse
	RingPeriods                    [30]int
	RingFrameCount, RingPulseCount int
}

func NewAnalyzer() *Analyzer {
	a := new(Analyzer)
	for i, f := range frequencies {
		a.Coeff[i] = 2 * math.Cos(2*math.Pi*f/8000)
	}
	background := [10]float64{250, 300, 350, 500, 600, 700, 850, 1000, 1200, 1500}
	for i := 0; i < 14; i++ {
		a.RingCoeff[i] = 2 * math.Cos(2*math.Pi*(395+float64(i)*5)/8000)
	}
	for i, f := range background {
		a.RingCoeff[i+14] = 2 * math.Cos(2*math.Pi*f/8000)
	}
	for i := range a.RingHann {
		a.RingHann[i] = 0.5 * (1 - math.Cos(2*math.Pi*float64(i)/3999))
	}
	return a
}
func (a *Analyzer) ringLevel(i int) float64 {
	x, y, c := a.RingX[i], a.RingY[i], a.RingCoeff[i]
	power := x*x + y*y - c*x*y
	if power <= 0 {
		return -120
	}
	amplitude := 4 * math.Sqrt(power) / 4000
	if amplitude <= 0 {
		return -120
	}
	return math.Max(-120, math.Min(0, 20*math.Log10(amplitude/32768)))
}
func (a *Analyzer) finishRingFrame() {
	i := a.RingFrameCount
	f := &a.RingFrames[i]
	*f = RingFrame{Index: i, Start: i * 500, End: (i + 1) * 500, State: "other", RMS: -120, Frequency: 425}
	rms := math.Sqrt(a.RingSquares / 4000)
	if rms > 0 {
		f.RMS = math.Max(-120, 20*math.Log10(rms/32768))
	}
	dc := a.RingSum / 4000
	acRMS := math.Sqrt(math.Max(0, a.RingSquares/4000-dc*dc))
	acDBFS := -120.0
	if acRMS > 0 {
		acDBFS = math.Max(-120, 20*math.Log10(acRMS/32768))
	}
	f.ACRMS = acDBFS
	best := -120.0
	for k := 0; k < 14; k++ {
		level := a.ringLevel(k)
		if level > best {
			best = level
			f.Frequency = 395 + float64(k)*5
		}
	}
	var bg [10]float64
	for k := range bg {
		bg[k] = a.ringLevel(k + 14)
	}
	sort.Float64s(bg[:])
	f.Level = best
	f.Prominence = best - (bg[4]+bg[5])/2
	f.Purity = best - acDBFS
	if best >= -48 && f.Prominence >= 10 && f.Purity >= 1.5 {
		f.State = "ring"
	} else if acDBFS <= -50 {
		f.State = "silence"
	}
	if f.State == "ring" {
		if a.RingPulseCount > 0 && a.RingPulses[a.RingPulseCount-1].End == f.Start {
			p := &a.RingPulses[a.RingPulseCount-1]
			p.End = f.End
			p.Duration = p.End - p.Start
			p.ToneFrames++
		} else {
			a.RingPulses[a.RingPulseCount] = RingPulse{f.Start, f.End, 500, 1}
			a.RingPulseCount++
		}
	}
	f.RMS = round(f.RMS, 2)
	f.ACRMS = round(f.ACRMS, 2)
	f.Level = round(f.Level, 2)
	f.Prominence = round(f.Prominence, 2)
	f.Purity = round(f.Purity, 2)
	a.RingFrameCount++
	a.RingIndex = 0
	a.RingSquares = 0
	a.RingSum = 0
	a.RingX = [24]float64{}
	a.RingY = [24]float64{}
}
func (a *Analyzer) ringSample(s int) {
	if a.RingFrameCount >= 30 {
		return
	}
	if s == 0 && a.RingSquares == 0 {
		a.RingIndex++
		if a.RingIndex == 4000 {
			a.finishRingFrame()
		}
		return
	}
	input := float64(s) * a.RingHann[a.RingIndex]
	a.RingSquares += float64(s * s)
	a.RingSum += float64(s)
	for i, c := range a.RingCoeff {
		next := input + c*a.RingX[i] - a.RingY[i]
		a.RingY[i] = a.RingX[i]
		a.RingX[i] = next
	}
	a.RingIndex++
	if a.RingIndex == 4000 {
		a.finishRingFrame()
	}
}
func (a *Analyzer) finishRing() RingResult {
	n := 0
	for i := 0; i < a.RingPulseCount; i++ {
		if a.RingPulses[i].ToneFrames >= 2 {
			a.RingPulses[n] = a.RingPulses[i]
			n++
		}
	}
	a.RingPulseCount = n
	r := RingResult{Duration: a.RingFrameCount * 500, PulseCount: n, HasPattern: n > 0, Reason: "nenhum_pulso_425hz"}
	var lengths, previous, indexes [30]int
	bestEnd, bestLength := -1, 0
	for i := 0; i < n; i++ {
		lengths[i] = 1
		previous[i] = -1
		for j := 0; j < i; j++ {
			period := a.RingPulses[i].Start - a.RingPulses[j].Start
			if absInt(period-5000) <= 600 && lengths[j]+1 > lengths[i] {
				lengths[i] = lengths[j] + 1
				previous[i] = j
			}
		}
		if lengths[i] > bestLength {
			bestLength = lengths[i]
			bestEnd = i
		}
	}
	count := 0
	for bestEnd >= 0 {
		indexes[count] = bestEnd
		count++
		bestEnd = previous[bestEnd]
	}
	for i := 0; i < count; i++ {
		p := a.RingPulses[indexes[count-i-1]]
		a.RingMatched[i] = p
		if i > 0 {
			a.RingPeriods[i-1] = p.Start - a.RingMatched[i-1].Start
		}
	}
	r.MatchedCount = count
	r.HasCadence = count >= 2
	start := -1
	for i := 0; i < a.RingFrameCount; i++ {
		middle := i*500 + 250
		protected := false
		for j := 0; j < count; j++ {
			p := a.RingMatched[j]
			if middle >= p.Start-400 && middle <= p.End+400 {
				protected = true
				break
			}
		}
		if a.RingFrames[i].State == "other" && !protected {
			if start < 0 {
				start = i * 500
			}
			continue
		}
		if start >= 0 {
			duration := i*500 - start
			if duration >= 300 {
				v := start
				r.DisturbanceAt = &v
				r.DisturbanceDuration = duration
				break
			}
			start = -1
		}
	}
	if r.DisturbanceAt == nil && start >= 0 {
		duration := r.Duration - start
		if duration >= 300 {
			v := start
			r.DisturbanceAt = &v
			r.DisturbanceDuration = duration
		}
	}
	r.FromStartToEnd = r.HasPattern && r.DisturbanceAt == nil
	cycle := 0.0
	if r.HasCadence {
		cycle = math.Min(1, 0.70+math.Max(0, float64(count-2))*0.15)
	} else if r.HasPattern {
		cycle = 0.45
	}
	if r.HasPattern {
		clean := 0.0
		if r.DisturbanceAt == nil {
			clean = 0.30
		}
		r.Confidence = round(cycle*0.70+clean, 4)
	}
	if n > 0 {
		if r.DisturbanceAt != nil {
			r.Reason = "ring_perturbado_por_outro_audio"
		} else if !r.HasCadence {
			r.Reason = "ring_detectado_cadencia_nao_confirmada"
		} else {
			r.Reason = "ring_presente_do_inicio_ao_fim"
		}
	}
	r.Pulses = a.RingPulses[:n]
	r.Matched = a.RingMatched[:count]
	r.Periods = a.RingPeriods[:max(0, count-1)]
	r.Frames = a.RingFrames[:a.RingFrameCount]
	return r
}
func sample(b []byte, i int) int { return int(int16(binary.LittleEndian.Uint16(b[i*2:]))) }
func round(v float64, n int) float64 {
	scale := 1000.0
	if n == 4 {
		scale = 10000
	} else if n == 2 {
		scale = 100
	}
	return math.Round(v*scale) / scale
}
func mean(v []float64) float64 {
	sum := 0.0
	for _, x := range v {
		sum += x
	}
	if len(v) == 0 {
		return 0
	}
	return sum / float64(len(v))
}
func std(v []float64, m float64) float64 {
	sum := 0.0
	for _, x := range v {
		d := x - m
		sum += d * d
	}
	if len(v) == 0 {
		return 0
	}
	return math.Sqrt(sum / float64(len(v)))
}
func absInt(x int) int {
	if x < 0 {
		return -x
	}
	return x
}
func (a *Analyzer) scan(b []byte) Frame {
	var intervals [160]float64
	count := 0
	prev := 0
	last := -1
	cross := 0
	sum, squares, absolute, difference := 0.0, 0.0, 0.0, 0.0
	for i := 0; i < 160; i++ {
		s := sample(b, i)
		a.ringSample(s)
		sum += float64(s)
		squares += float64(s * s)
		absolute += float64(absInt(s))
		if i > 0 {
			difference += float64(absInt(s - prev))
			if prev <= 0 && s > 0 {
				if last >= 0 {
					intervals[count] = float64(i - last)
					count++
				}
				last = i
				cross++
			}
		}
		prev = s
	}
	dc := sum / 160
	rms := math.Sqrt(math.Max(0, squares/160-dc*dc))
	db := -120.0
	if rms > 0 {
		db = math.Max(-120, 20*math.Log10(rms/32768))
	}
	im := mean(intervals[:count])
	cv := -1.0
	if im > 0 {
		cv = std(intervals[:count], im) / im
	}
	diff := 0.0
	if absolute > 0 {
		diff = difference / absolute
	}
	return Frame{db, cv, diff, cross, db >= -42}
}
func (a *Analyzer) spectral(pcm []byte, start, frames int, f *Features) {
	step := (frames + 19) / 20
	if step < 5 {
		step = 5
	}
	var prev [8]float64
	var dominance, entropy, variation [151]float64
	count, variations := 0, 0
	havePrev := false
	threshold := 32768.0 * 32768.0 * math.Pow(10, -42.0/10)
	for frame := 0; frame < frames; frame += step {
		b := pcm[(start+frame)*320:]
		square := 0.0
		for i := 0; i < 160; i++ {
			s := float64(sample(b, i))
			square += s * s
		}
		if square/160 < threshold {
			continue
		}
		var power [8]float64
		total := 0.0
		for k, c := range a.Coeff {
			x, y := 0.0, 0.0
			for i := 0; i < 160; i++ {
				next := float64(sample(b, i)) + c*x - y
				y = x
				x = next
			}
			power[k] = math.Max(0, x*x+y*y-c*x*y)
			total += power[k]
		}
		if total <= 0 {
			continue
		}
		top, ent, dist := 0.0, 0.0, 0.0
		for k, p := range power {
			v := p / total
			if v > top {
				top = v
			}
			if v > 0 {
				ent -= v * math.Log(v)
			}
			if havePrev {
				dist += math.Abs(v - prev[k])
			}
			prev[k] = v
		}
		dominance[count] = top
		entropy[count] = ent / math.Log(8)
		count++
		if havePrev {
			variation[variations] = dist / 2
			variations++
		}
		havePrev = true
	}
	f.Dominance = round(mean(dominance[:count]), 4)
	f.Entropy = round(mean(entropy[:count]), 4)
	f.Variability = round(mean(variation[:variations]), 4)
}
func (a *Analyzer) classify(pcm []byte, start, end int) Features {
	f := Features{Signal: "silence", RMSMean: -120}
	var rms, cross, cvs, diff [maxFrames]float64
	count, ncv, stable := 0, 0, 0
	for i := start; i < end; i++ {
		v := a.Frames[i]
		if !v.Active {
			continue
		}
		if count == 0 {
			f.FirstActive = (i - start) * 20
		}
		rms[count] = v.RMS
		cross[count] = float64(v.Cross)
		diff[count] = v.Diff
		count++
		if v.CV >= 0 {
			cvs[ncv] = v.CV
			ncv++
			if v.CV < 0.12 {
				stable++
			}
		}
	}
	if count == 0 {
		return f
	}
	f.ActiveFrames = count
	rm, cm, dm := mean(rms[:count]), mean(cross[:count]), mean(diff[:count])
	rs, cs := std(rms[:count], rm), std(cross[:count], cm)
	sort.Float64s(cvs[:ncv])
	cv := 0.0
	if ncv > 0 {
		cv = cvs[ncv/2]
		if ncv%2 == 0 {
			cv = (cvs[ncv/2-1] + cv) / 2
		}
	}
	a.spectral(pcm, start, end-start, &f)
	tone := float64(stable)/float64(count) >= 0.65 && rs < 1.8 || cm < 3 && rs < 0.8 && cs < 1.2 && dm < 0.85
	noise := !tone && cm >= 34 && dm >= 0.90
	music := !tone && !noise && count >= 40 && ((f.Variability < 0.10 && rs >= 1.4) || (f.Variability < 0.20 && cs < 1.5 && f.Dominance >= 0.70 && f.Entropy >= 0.35) || (rs < 1.4 && cs >= 2 && f.Entropy >= 0.4))
	voice := !tone && !noise && !music && cm >= 1.5 && cm <= 33 && dm >= 0.08 && rs >= 1.4
	switch {
	case tone:
		f.Signal = "narrowband_tone"
	case noise:
		f.Signal = "noise"
	case music:
		f.Signal = "music_like"
	case voice:
		f.Signal = "voice_like"
	default:
		f.Signal = "other_audio"
	}
	f.RMSMean = round(rm, 3)
	f.RMSStd = round(rs, 3)
	f.CrossMean = round(cm, 3)
	f.CrossStd = round(cs, 3)
	f.CV = round(cv, 3)
	f.Difference = round(dm, 3)
	return f
}
func (a *Analyzer) appendRegion(pcm []byte, r *Result, start, end int, count *int) {
	s := &a.Segments[*count]
	*s = Segment{}
	*count++
	s.Start = start * 20
	s.End = end * 20
	s.Duration = (end - start) * 20
	s.Features = a.classify(pcm, start, end)
	s.Signal = s.Features.Signal
	if *count == 1 {
		r.SignalFeatures = s.Features
	}
	r.Active += s.Duration
	switch s.Signal {
	case "narrowband_tone":
		r.Tone += s.Duration
	case "noise":
		r.Noise += s.Duration
	case "music_like":
		r.Music += s.Duration
	case "voice_like":
		current, gap, first := 0, 0, -1
		for i := start; i < end; i++ {
			if a.Frames[i].Active {
				if first < 0 {
					first = i * 20
				}
				s.Voice += 20
				current += 20
				gap = 0
			} else if current > 0 {
				gap += 20
				if gap > 120 {
					if current > s.Longest {
						s.Longest = current
					}
					current = 0
					gap = 0
				}
			}
		}
		if current > s.Longest {
			s.Longest = current
		}
		r.Voice += s.Voice
		if s.Longest > r.Longest {
			r.Longest = s.Longest
		}
		if s.Voice > 0 {
			if r.SegmentCount == 0 {
				r.FirstVoice = first
				r.FirstVoiceSegment = s.Voice
			} else {
				pause := s.Start - r.LastVoice
				if pause > 0 {
					r.MeanPause += float64(pause)
				}
				r.PauseCount++
			}
			r.LastVoice = s.End
			r.SignalFeatures = s.Features
			r.SegmentCount++
		}
	}
}
func (a *Analyzer) Analyze(pcm []byte) (Result, error) {
	r := Result{Duration: len(pcm) / 16, FirstVoice: -1, LastVoice: -1, Signal: "silence", SignalFeatures: Features{Signal: "silence", RMSMean: -120}}
	if len(pcm)%2 != 0 || len(pcm) > 240000 {
		return r, fmt.Errorf("invalid PCM length %d", len(pcm))
	}
	frames := len(pcm) / 320
	a.RingX = [24]float64{}
	a.RingY = [24]float64{}
	a.RingSquares = 0
	a.RingSum = 0
	a.RingIndex = 0
	a.RingFrameCount = 0
	a.RingPulseCount = 0
	start, last, count := -1, -1, 0
	for i := 0; i < frames; i++ {
		a.Frames[i] = a.scan(pcm[i*320:])
		if !a.Frames[i].Active {
			continue
		}
		if last >= 0 && i-last > 10 {
			if count >= maxSegments {
				return r, fmt.Errorf("segment limit")
			}
			a.appendRegion(pcm, &r, start, last+1, &count)
			start = -1
		}
		if start < 0 {
			start = i
		}
		last = i
	}
	if start >= 0 {
		if count >= maxSegments {
			return r, fmt.Errorf("segment limit")
		}
		a.appendRegion(pcm, &r, start, last+1, &count)
	}
	r.Silence = r.Duration - r.Active
	if r.Silence < 0 {
		r.Silence = 0
	}
	r.SilenceRatio = 1
	if r.Duration > 0 {
		r.VoiceRatio = round(float64(r.Voice)/float64(r.Duration), 4)
		r.SilenceRatio = round(float64(r.Silence)/float64(r.Duration), 4)
	}
	if r.PauseCount > 0 {
		r.MeanPause = math.Round(r.MeanPause/float64(r.PauseCount)*100) / 100
	}
	switch {
	case r.Voice > 0:
		r.Signal = "voice_like"
	case r.Music >= r.Tone && r.Music >= r.Noise && r.Music > 0:
		r.Signal = "music_like"
	case r.Tone >= r.Noise && r.Tone > 0:
		r.Signal = "narrowband_tone"
	case r.Noise > 0:
		r.Signal = "noise"
	case r.Active > 0:
		r.Signal = "other_audio"
	}
	r.Segments = a.Segments[:count]
	r.Ring = a.finishRing()
	if len(pcm) < 8000 {
		r.Ring.Duration = r.Duration
		if len(pcm) == 0 {
			r.Ring.Reason = "pcm_vazio"
		} else {
			r.Ring.Reason = "pcm_muito_curto"
		}
	}
	return r, nil
}

var sink uint64

func digest(r Result) uint64 {
	v := uint64(r.Duration)*1315423911 + uint64(r.Voice)*2654435761 + uint64(r.Longest)*97 + uint64(r.Active)*31 + uint64(len(r.Segments)) + uint64(r.Ring.PulseCount)*7919 + uint64(r.Ring.MatchedCount)*1543
	for _, s := range r.Segments {
		v = v*1099511628211 + uint64(s.Start*7+s.End*11+s.Voice*13) + uint64(math.Float64bits(s.Features.Dominance))
	}
	return v
}
func percentile(v []float64, p float64) float64 {
	sort.Float64s(v)
	i := int(math.Ceil(float64(len(v))*p)) - 1
	if i < 0 {
		i = 0
	}
	return v[i]
}
func rssKB() int64 {
	b, _ := os.ReadFile("/proc/self/status")
	for _, line := range strings.Split(string(b), "\n") {
		if strings.HasPrefix(line, "VmRSS:") {
			x := strings.Fields(line)
			if len(x) > 1 {
				n, _ := strconv.ParseInt(x[1], 10, 64)
				return n
			}
		}
	}
	return 0
}
func peakRSSKB() int64 {
	b, _ := os.ReadFile("/proc/self/status")
	for _, line := range strings.Split(string(b), "\n") {
		if strings.HasPrefix(line, "VmHWM:") {
			x := strings.Fields(line)
			if len(x) > 1 {
				n, _ := strconv.ParseInt(x[1], 10, 64)
				return n
			}
		}
	}
	return 0
}
func cpuMs() float64 {
	var u syscall.Rusage
	syscall.Getrusage(syscall.RUSAGE_SELF, &u)
	return float64(u.Utime.Sec+u.Stime.Sec)*1000 + float64(u.Utime.Usec+u.Stime.Usec)/1000
}
func main() {
	mode := flag.String("mode", "results", "results or bench")
	dir := flag.String("dir", "bench/fixtures", "fixture directory")
	runs := flag.Int("runs", 1000, "jobs per duration")
	workers := flag.Int("workers", 1, "concurrent analyzers")
	cpuProfile := flag.String("cpuprofile", "", "write Go CPU profile (outside ordinary benchmarks)")
	flag.Parse()
	if *cpuProfile != "" {
		file, err := os.Create(*cpuProfile)
		if err != nil {
			panic(err)
		}
		if err := pprof.StartCPUProfile(file); err != nil {
			panic(err)
		}
		defer func() { pprof.StopCPUProfile(); file.Close() }()
	}
	files, err := filepath.Glob(filepath.Join(*dir, "*.pcm"))
	if err != nil || len(files) == 0 {
		panic("no fixtures")
	}
	a := NewAnalyzer()
	if *workers < 1 || *workers > 16 {
		panic("invalid worker count")
	}
	runtime.GOMAXPROCS(*workers)
	if *mode == "results" {
		for _, name := range files {
			b, e := os.ReadFile(name)
			if e != nil {
				panic(e)
			}
			r, e := a.Analyze(b)
			if e != nil {
				panic(e)
			}
			out := map[string]any{"fixture": filepath.Base(name), "result": r, "digest": digest(r)}
			v, _ := json.Marshal(out)
			fmt.Println(string(v))
		}
		return
	}
	for _, seconds := range []int{1, 3, 5, 10, 15} {
		var inputs [][]byte
		for _, name := range files {
			if strings.HasPrefix(filepath.Base(name), fmt.Sprintf("%02ds_", seconds)) {
				b, e := os.ReadFile(name)
				if e != nil {
					panic(e)
				}
				inputs = append(inputs, b)
			}
		}
		if len(inputs) == 0 {
			continue
		}
		analyzers := make([]*Analyzer, *workers)
		analyzers[0] = a
		for worker := 1; worker < *workers; worker++ {
			analyzers[worker] = NewAnalyzer()
		}
		for i := 0; i < 25; i++ {
			r, _ := analyzers[i%*workers].Analyze(inputs[i%len(inputs)])
			sink += digest(r)
		}
		times := make([]float64, *runs)
		var memoryBefore, memoryAfter runtime.MemStats
		runtime.GC()
		runtime.ReadMemStats(&memoryBefore)
		initialRSS := rssKB()
		cpuStart := cpuMs()
		wallStart := time.Now()
		if *workers == 1 {
			for i := 0; i < *runs; i++ {
				start := time.Now()
				r, e := a.Analyze(inputs[i%len(inputs)])
				if e != nil {
					panic(e)
				}
				sink += digest(r)
				times[i] = float64(time.Since(start).Nanoseconds()) / 1e6
			}
		} else {
			var group sync.WaitGroup
			checksums := make([]uint64, *workers)
			for worker := 0; worker < *workers; worker++ {
				group.Add(1)
				go func(worker int) {
					defer group.Done()
					local := analyzers[worker]
					for i := worker; i < *runs; i += *workers {
						start := time.Now()
						r, e := local.Analyze(inputs[i%len(inputs)])
						if e != nil {
							panic(e)
						}
						checksums[worker] += digest(r)
						times[i] = float64(time.Since(start).Nanoseconds()) / 1e6
					}
				}(worker)
			}
			group.Wait()
			for _, checksum := range checksums {
				sink += checksum
			}
		}
		elapsed := time.Since(wallStart).Seconds()
		cpu := cpuMs() - cpuStart
		runtime.ReadMemStats(&memoryAfter)
		row := map[string]any{"duration_s": seconds, "workers": *workers, "jobs": *runs, "p50_ms": percentile(times, 0.5), "p95_ms": percentile(times, 0.95), "p99_ms": percentile(times, 0.99), "max_ms": times[len(times)-1], "cpu_ms_per_job": cpu / float64(*runs), "jobs_s": float64(*runs) / elapsed, "rss_initial_kb": initialRSS, "rss_peak_kb": peakRSSKB(), "rss_final_kb": rssKB(), "allocs_per_job": float64(memoryAfter.Mallocs-memoryBefore.Mallocs) / float64(*runs), "allocated_bytes_per_job": float64(memoryAfter.TotalAlloc-memoryBefore.TotalAlloc) / float64(*runs), "digest": sink}
		v, _ := json.Marshal(row)
		fmt.Println(string(v))
	}
}
