#include <algorithm>
#include <chrono>
#include <cstdint>
#include <cstdlib>
#include <iomanip>
#include <iostream>
#include <string>

static std::string monoToStereo(const std::string& pcmData)
{
    if (pcmData.size() < 2) {
        return pcmData;
    }

    const std::size_t len = pcmData.size();

    // Igual ao Go:
    // make([]byte, 0, length*2)
    std::string stereo;
    stereo.reserve(len * 2);

    for (std::size_t i = 0; i < len; i += 2) {

        // Equivalente a:
        //
        // sample := strings.Clone(pcmData[i:i+2])
        //
        // Cria uma NOVA std::string e copia os 2 bytes.
        const std::string sample(
            pcmData.data() + i,
            2
        );

        // Equivalente aos dois append() do Go.
        stereo.append(sample);
        stereo.append(sample);
    }

    return stereo;
}

static void runBenchmark(
    std::uint64_t iterations,
    std::size_t samplesPerFrame
)
{
    if (iterations < 1) {
        iterations = 1;
    }

    if (samplesPerFrame < 1) {
        samplesPerFrame = 1;
    }

    std::string pcmMono;
    pcmMono.reserve(samplesPerFrame * 2);

    for (std::size_t i = 0; i < samplesPerFrame; ++i) {
        pcmMono.push_back('\x01');
        pcmMono.push_back('\x02');
    }

    const std::size_t expectedInputBytes =
        samplesPerFrame * 2;

    const std::size_t expectedOutputBytes =
        expectedInputBytes * 2;

    // Validação funcional
    const std::string probe =
        monoToStereo(pcmMono);

    if (probe.size() != expectedOutputBytes) {
        std::cerr
            << "ERRO: tamanho de saida inesperado\n";
        return;
    }

    if (
        probe.size() < 4 ||
        static_cast<unsigned char>(probe[0]) != 0x01 ||
        static_cast<unsigned char>(probe[1]) != 0x02 ||
        static_cast<unsigned char>(probe[2]) != 0x01 ||
        static_cast<unsigned char>(probe[3]) != 0x02
    ) {
        std::cerr
            << "ERRO: canais L/R incorretos\n";
        return;
    }

    // Warm-up
    const std::uint64_t warmup =
        std::min<std::uint64_t>(
            1000,
            iterations
        );

    std::uint64_t warmChecksum = 0;

    for (
        std::uint64_t i = 0;
        i < warmup;
        ++i
    ) {
        const std::string stereo =
            monoToStereo(pcmMono);

        warmChecksum += stereo.size();
    }

    if (warmChecksum == 0) {
        std::cerr
            << "ERRO: warmup invalido\n";
        return;
    }

    std::uint64_t checksum = 0;

    const auto start =
        std::chrono::steady_clock::now();

    for (
        std::uint64_t i = 0;
        i < iterations;
        ++i
    ) {
        const std::string stereo =
            monoToStereo(pcmMono);

        checksum += stereo.size();
    }

    const auto end =
        std::chrono::steady_clock::now();

    const double elapsed =
        std::chrono::duration<double>(
            end - start
        ).count();

    const double totalInputBytes =
        static_cast<double>(
            expectedInputBytes
        ) *
        static_cast<double>(
            iterations
        );

    const double totalOutputBytes =
        static_cast<double>(
            expectedOutputBytes
        ) *
        static_cast<double>(
            iterations
        );

    const double callsPerSecond =
        static_cast<double>(
            iterations
        ) / elapsed;

    const double inputMiBPerSecond =
        (totalInputBytes / 1048576.0)
        / elapsed;

    const double outputMiBPerSecond =
        (totalOutputBytes / 1048576.0)
        / elapsed;

    std::cout << std::setprecision(15);

    std::cout
        << "=== monoToStereo / C++ equivalent ===\n";

    std::cout
        << "iterations: "
        << iterations
        << "\n";

    std::cout
        << "samples/frame: "
        << samplesPerFrame
        << "\n";

    std::cout
        << "input/frame: "
        << expectedInputBytes
        << " bytes\n";

    std::cout
        << "output/frame: "
        << expectedOutputBytes
        << " bytes\n";

    std::cout
        << "elapsed: "
        << elapsed
        << " s\n";

    std::cout
        << "calls/s: "
        << callsPerSecond
        << "\n";

    std::cout
        << "input MiB/s: "
        << inputMiBPerSecond
        << "\n";

    std::cout
        << "output MiB/s: "
        << outputMiBPerSecond
        << "\n";

    std::cout
        << "checksum: "
        << checksum
        << "\n";
}

int main(int argc, char** argv)
{
    std::uint64_t iterations = 50000;
    std::size_t samplesPerFrame = 960;

    if (argc > 1) {
        iterations =
            std::strtoull(
                argv[1],
                nullptr,
                10
            );
    }

    if (argc > 2) {
        samplesPerFrame =
            static_cast<std::size_t>(
                std::strtoull(
                    argv[2],
                    nullptr,
                    10
                )
            );
    }

    runBenchmark(
        iterations,
        samplesPerFrame
    );

    return 0;
}