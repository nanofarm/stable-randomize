
export default {
  name: 'WinnerReveal',
  data() {
    return {
      show: false,
      spinning: true,
      winnerNames: '',
      showNames: false,
    }
  },
  methods: {
    start() {
      this.show = true
      this.spinning = true
      this.winnerNames = ''
      this.showNames = false
    },

    reveal(names) {
      this.spinning = false
      this.winnerNames = names
      setTimeout(() => { this.showNames = true }, 300)
      this.spawnConfetti()
    },

    close() {
      this.show = false
      this.$emit('close')
    },

    spawnConfetti() {
      const container = this.$refs.confetti
      if (!container) return
      container.innerHTML = ''
      const colors = ['#00d4ff', '#a855f7', '#ff2d8a', '#00ff88', '#ff8800', '#ffe600']
      for (let i = 0; i < 40; i++) {
        const p = document.createElement('div')
        p.className = 'cf-p'
        const angle = Math.PI * 2 * i / 40
        const dist = 100 + Math.random() * 200
        p.style.cssText = `
          --tx: ${Math.cos(angle) * dist}px;
          --ty: ${Math.sin(angle) * dist}px;
          background: ${colors[i % colors.length]};
          border-radius: ${Math.random() > .5 ? '50%' : '2px'};
          width: ${6 + Math.random() * 8}px;
          height: ${6 + Math.random() * 8}px;
          animation-delay: ${Math.random() * .3}s;
        `
        container.appendChild(p)
      }
    },
  },
  template: `
    <div class="reveal" v-if="show">
      <div class="reveal-c">
        <div class="reveal-cf" ref="confetti"></div>
        <div class="reveal-t">Определяем победителя...</div>
        <div class="reveal-sp" :class="{ done: !spinning }">
          <div class="reveal-sp-t">{{ spinning ? '🎰' : '🏆' }}</div>
        </div>
        <div class="reveal-wn" :class="{ show: showNames }" style="white-space:pre-line">{{ winnerNames }}</div>
        <button v-if="showNames" class="btn btn-s btn-full" style="margin-top:30px" @click="close">
          🎉 Отлично!
        </button>
      </div>
    </div>
  `,
}
