import { ApiProperty } from '@nestjs/swagger';
import { OrderDto } from './order.dto';

export class OrdersPageDto {
  @ApiProperty({ type: [OrderDto] })
  items!: OrderDto[];

  @ApiProperty({ example: 1 })
  page!: number;

  @ApiProperty({ example: 10 })
  perPage!: number;

  @ApiProperty({ example: 100 })
  total!: number;
}

