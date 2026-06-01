import { ApiProperty } from '@nestjs/swagger';

export class OrderServiceItemDto {
  @ApiProperty()
  id!: number;

  @ApiProperty()
  workOrderId!: number;

  @ApiProperty()
  serviceId!: number;

  @ApiProperty()
  serviceName!: string;

  @ApiProperty()
  quantity!: number;

  @ApiProperty()
  servicePrice!: number;

  @ApiProperty()
  totalPrice!: number;

  @ApiProperty()
  createdAt!: string;
}

