(function () {
	const data = JSON.parse(document.getElementById('tree-data').textContent)

	const width = window.innerWidth - 40
	const height = window.innerHeight * 4

	// Контейнер для дерева
	const container = d3.select("#tree-container")

	const svg = container
		.append("svg")
		.attr("width", "100%")  // Установлено на 100% для гибкого масштаба
		.attr("height", "100%")  // Установлено на 100% для гибкого масштаба
		.attr("viewBox", `0 0 ${width} ${height}`)  // Настройка viewBox для масштабирования
		.attr("preserveAspectRatio", "xMinYMin meet")  // Сохранение пропорций
		.append("g")
		.attr("transform", "translate(80,0)")

	const zoom = d3.zoom()
		.scaleExtent([0.1, 3])
		.on("zoom", (event) => {
			svg.attr("transform", event.transform)
		})

	// Применяем zoom к основному SVG элементу
	d3.select("svg").call(zoom)

	// Строим дерево
	const tree = d3.tree()
		.size([height, width - 160])
		.separation((a, b) => (a.parent == b.parent ? 20 : 32) + (a.depth * 1.5))

	const root = d3.hierarchy(data[0])
	tree(root)

	// Создаем связи
	const link = svg.selectAll(".link")
		.data(root.links())
		.enter().append("path")
		.attr("class", "link")
		.attr("d", d3.linkHorizontal()
			.x(d => d.y)
			.y(d => d.x))
		.style("stroke-width", d => 10 - d.source.depth * 1.5)  // Толщина уменьшается с глубиной узла
		.style("stroke", "#e0e0e0")  // Цвет линии
		.style("fill", "none")    // Убираем заливку для линий

	// Создаем узлы
	const node = svg.selectAll(".node")
		.data(root.descendants())
		.enter().append("g")
		.attr("class", "node")
		.attr("transform", d => `translate(${d.y},${d.x})`)

	node.append("circle")
		.attr("r", d => d.children ? 8 : 5)
		.attr("fill", d => d.children ? "#ffcc00" : "#00bfff")
		.attr("class", d => d.data.type)

	// Динамический размер шрифта с еще большим размером для корневого узла
	node.append("text")
		.attr("dy", ".31em")
		.attr("x", d => d.children ? -20 : 8)
		.style("text-anchor", d => d.children ? "end" : "start")
		.style("font-size", d => `${36 - d.depth * 3.8}px`)  // Корневой узел: 36px, дочерние уменьшаются
		.text(d => `${d.data.name} (${d.data.percentage}%)`)
		.call(wrap, 1200)

	function wrap(text, width) {
		text.each(function () {
			const text = d3.select(this)
			const words = text.text().split(/\s+/).reverse()
			let word, line = [], lineNumber = 0
			const x = text.attr("x"), y = text.attr("y"), dy = parseFloat(text.attr("dy"))
			let tspan = text.text(null).append("tspan").attr("x", x).attr("y", y).attr("dy", `${dy}em`)

			while (word = words.pop()) {
				line.push(word)
				tspan.text(line.join(" "))
				if (tspan.node().getComputedTextLength() > width) {
					line.pop()
					tspan.text(line.join(" "))
					line = [word]
					tspan = text.append("tspan").attr("x", x).attr("y", y).attr("dy", `${++lineNumber + dy}em`).text(word)
				}
			}
		})
	}
})()
